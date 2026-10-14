import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useGetBrandingQuery } from '../branding/brandingApi'
import { useGetHealthQuery, useGetReadinessQuery } from '../diagnostics/diagnosticsApi'
import { useAppDispatch, useAppSelector } from '../../app/hooks'
import { safeAssetUrl } from '../../shared/theme/branding'
import { useTenantFormat } from '../../shared/theme/useTenantFormat'
import { BrandLogo } from '../../shared/ui/BrandLogo'
import { Button } from '../../shared/ui/Button'
import { LanguageSwitcher } from '../../shared/ui/LanguageSwitcher'
import {
  hardwareBridge,
  TerminalDeviceService,
} from './hardware'
import {
  actionChosen,
  attendanceRecorded,
  employeeIdentified,
  fingerprintAccepted,
  resetTerminal,
  terminalFailed,
  terminalStep,
  terminalTimedOut,
} from './kioskSlice'
import { commitAttendance, enrollTerminal, identifyEmployee, recordHeartbeat, TerminalNotEnrolledError, TerminalRequestError, uploadPhoto, verifyFingerprint } from './terminalClient'
import { applyScannerKey, failurePresentation, idleReturnMs, offeredActions, rememberIdempotencyKey, stepAfterAction, stepAfterVerification, terminalTiming, type AttemptPhase, type FailurePresentation, type TerminalStep } from './terminalMachine'
import { readTerminalCredential, writeTerminalCredential } from './terminalCredential'
import { createDeviceKey } from './ed25519'

export function KioskPage() {
  const { t } = useTranslation()
  const dispatch = useAppDispatch()
  const kiosk = useAppSelector((state) => state.kiosk)
  const branding = useGetBrandingQuery()
  const health = useGetHealthQuery()
  const readiness = useGetReadinessQuery(undefined, { pollingInterval: 15000 })
  const [code, setCode] = useState('')
  const submitRef = useRef<(value: string) => void>(() => {})
  const [enrolled, setEnrolled] = useState(() => readTerminalCredential() !== null)
  const [credentialVersion, setCredentialVersion] = useState(0)
  const [registrationCode, setRegistrationCode] = useState('')
  const [replacing, setReplacing] = useState(false)
  const flight = useRef<string | null>(null)
  const commitKeys = useRef(new Map<string, string>())
  const photoStarted = useRef(false)
  const captureRef = useRef<() => void>(() => {})
  const production = import.meta.env.PROD
  const devices = TerminalDeviceService.resolve(hardwareBridge(), production)
  const fingerprintMode = devices.fingerprint.vendorId()
  const cameraMode = devices.camera.vendorId()
  const format = useTenantFormat()
  const zone = kiosk.terminalTimezone || branding.data?.data.timezone || 'UTC'
  const serverInstant = health.data?.data.time
  const [clockInstant, setClockInstant] = useState(serverInstant ?? new Date().toISOString())
  const backendUnavailable = readiness.isError || readiness.data?.data.status === 'failed'
  const actions = offeredActions(kiosk.allowedActions)

  useEffect(() => {
    const parsed = serverInstant ? Date.parse(serverInstant) : Number.NaN
    const offset = Number.isNaN(parsed) ? 0 : parsed - Date.now()
    const paint = () => setClockInstant(new Date(Date.now() + offset).toISOString())
    paint()
    const timer = window.setInterval(paint, 1000)

    return () => window.clearInterval(timer)
  }, [serverInstant])
  const clock = format.dateTime(clockInstant, zone)

  useEffect(() => {
    const delay = idleReturnMs(kiosk.step)
    if (delay === null) {
      return
    }
    const timer = window.setTimeout(() => {
      if (kiosk.step === 'success' || kiosk.step === 'error' || kiosk.step === 'timeout') {
        commitKeys.current.clear()
        dispatch(resetTerminal())
        setCode('')
      } else {
        dispatch(terminalTimedOut())
      }
    }, delay)

    return () => window.clearTimeout(timer)
  }, [dispatch, kiosk.step])

  useEffect(() => {
    if (kiosk.step !== 'idle' || backendUnavailable) {
      return
    }
    const onKey = (event: KeyboardEvent) => {
      if (event.target instanceof HTMLInputElement || event.target instanceof HTMLTextAreaElement) {
        return
      }
      const next = applyScannerKey(code, event.key)
      if (next.submit) {
        event.preventDefault()
        submitRef.current(next.code)
      } else if (next.code !== code) {
        setCode(next.code)
      }
    }
    window.addEventListener('keydown', onKey)

    return () => window.removeEventListener('keydown', onKey)
  }, [backendUnavailable, code, kiosk.step])

  useEffect(() => {
    if (!enrolled || kiosk.step !== 'idle' || backendUnavailable) {
      return
    }
    const send = () => {
      void recordHeartbeat({
        os: navigator.userAgent.slice(0, 120),
        fingerprintAdapter: fingerprintMode,
        cameraAdapter: cameraMode,
      }).catch(() => undefined)
    }
    send()
    const timer = window.setInterval(send, 60000)

    return () => window.clearInterval(timer)
  }, [backendUnavailable, cameraMode, credentialVersion, enrolled, fingerprintMode, kiosk.step])

  function fail(phase: AttemptPhase, error: unknown) {
    dispatch(terminalFailed(present(phase, error)))
    setCode('')
  }

  async function submitCode(value: string) {
    const trimmed = value.trim()
    if (trimmed === '' || backendUnavailable || flight.current) {
      return
    }
    flight.current = 'identify'
    dispatch(terminalStep('identifying'))
    try {
      const identified = await identifyEmployee(trimmed)
      dispatch(employeeIdentified({
        attemptId: identified.attemptId,
        employee: identified.employee,
        terminalTimezone: identified.terminalTimezone,
      }))
      setCode('')
    } catch (error) {
      fail('identify', error)
    } finally {
      flight.current = null
    }
  }
  submitRef.current = (value: string) => { void submitCode(value) }

  async function enroll(token: string) {
    const trimmed = token.trim()
    if (trimmed === '' || flight.current) {
      return
    }
    flight.current = 'enroll'
    try {
      const key = await createDeviceKey()
      const enrolledDevice = await enrollTerminal(trimmed, key.publicKey)
      writeTerminalCredential({ deviceId: enrolledDevice.deviceId, seed: key.seed })
      setEnrolled(true)
      setCredentialVersion((current) => current + 1)
      setReplacing(false)
      setRegistrationCode('')
      dispatch(resetTerminal())
    } catch (error) {
      fail('identify', error)
    } finally {
      flight.current = null
    }
  }

  async function readFingerprint() {
    if (!kiosk.attemptId || flight.current) {
      return
    }
    const attemptId = kiosk.attemptId
    flight.current = 'fingerprint'
    dispatch(terminalStep('waiting_for_fingerprint'))
    try {
      const captured = await devices.readFingerprint(attemptId, terminalTiming.fingerprintMs)
      if (captured.status !== 'captured') {
        dispatch(terminalFailed(failurePresentation('fingerprint', captured.error)))
        return
      }
      dispatch(terminalStep('verifying'))
      const verified = await verifyFingerprint(attemptId, devices.fingerprint.vendorId(), captured.value.sample)
      const allowed = offeredActions(verified.allowedActions)
      dispatch(fingerprintAccepted({ state: verified.state, allowedActions: allowed }))
      const next = stepAfterVerification(allowed)
      dispatch(terminalStep(next))
      if (next === 'error') {
        dispatch(terminalFailed(failurePresentation('commit', 'illegal_transition')))
        return
      }
      if (next === 'processing_attendance' && allowed[0]) {
        await record(attemptId, allowed[0].action)
      }
    } catch (error) {
      fail('fingerprint', error)
    } finally {
      flight.current = null
    }
  }

  async function choose(action: string) {
    if (!kiosk.attemptId || flight.current) {
      return
    }
    const selected = actions.find((candidate) => candidate.action === action)
    if (!selected) {
      return
    }
    dispatch(actionChosen(action))
    const next = stepAfterAction(selected)
    dispatch(terminalStep(next))
    if (next === 'processing_attendance') {
      await record(kiosk.attemptId, action)
    }
  }

  useEffect(() => {
    if (kiosk.step !== 'capturing_photo') {
      photoStarted.current = false
      return
    }
    if (photoStarted.current) {
      return
    }
    photoStarted.current = true
    captureRef.current()
  }, [kiosk.step])

  async function capturePhoto() {
    if (!kiosk.attemptId || !kiosk.selectedAction || flight.current === 'photo') {
      return
    }
    const attemptId = kiosk.attemptId
    const action = kiosk.selectedAction
    flight.current = 'photo'
    try {
      const captured = await devices.takePhotograph(attemptId, terminalTiming.cameraMs)
      if (captured.status !== 'captured') {
        dispatch(terminalFailed(failurePresentation('photo', captured.error)))
        return
      }
      dispatch(terminalStep('processing_attendance'))
      await uploadPhoto(attemptId, captured.value.contentType, captured.value.bytes)
      await record(attemptId, action)
    } catch (error) {
      fail('photo', error)
    } finally {
      if (flight.current === 'photo') {
        flight.current = null
      }
    }
  }
  captureRef.current = () => { void capturePhoto() }

  async function record(attemptId: string, action: string) {
    if (flight.current === 'commit') {
      return
    }
    flight.current = 'commit'
    dispatch(terminalStep('processing_attendance'))
    const idempotencyKey = rememberIdempotencyKey(commitKeys.current, attemptId, action, () => crypto.randomUUID())
    try {
      const recorded = await commitAttendance(attemptId, action, idempotencyKey)
      dispatch(attendanceRecorded({
        action: recorded.action,
        occurredAt: recorded.occurredAt,
        state: recorded.state,
      }))
    } catch (error) {
      fail('commit', error)
    } finally {
      if (flight.current === 'commit') {
        flight.current = null
      }
    }
  }

  function retry() {
    if (kiosk.retry === 'fingerprint') {
      void readFingerprint()
      return
    }
    if (kiosk.retry === 'photo') {
      dispatch(terminalStep('capturing_photo'))
      return
    }
    if (kiosk.retry === 'commit' && kiosk.attemptId && kiosk.selectedAction) {
      void record(kiosk.attemptId, kiosk.selectedAction)
      return
    }
    commitKeys.current.clear()
    dispatch(resetTerminal())
    setCode('')
  }

  function actionLabel(action: string): string {
    if (action === 'clock_in' || action === 'clock_out' || action === 'lunch_start' || action === 'lunch_end') {
      return t(`attendance.${action}`)
    }

    return t('kiosk.reason.unknown')
  }

  const showUnavailable = backendUnavailable && (kiosk.step === 'idle' || kiosk.step === 'error' || kiosk.step === 'timeout')
  const scanning = !showUnavailable && kiosk.step === 'idle' && enrolled && !replacing && code.trim() !== ''
  const tone = kioskTone(kiosk.step, showUnavailable, scanning)
  const returnMs = kiosk.step === 'success' || kiosk.step === 'error' || kiosk.step === 'timeout' ? idleReturnMs(kiosk.step) : null
  const company = branding.data?.data.companyName ?? branding.data?.data.displayName ?? t('app.name')
  const logo = safeAssetUrl(branding.data?.data.logoUrl)
  const waiting = kiosk.step === 'identifying' || kiosk.step === 'waiting_for_fingerprint' || kiosk.step === 'verifying' || kiosk.step === 'capturing_photo' || kiosk.step === 'processing_attendance'

  function abandon() {
    commitKeys.current.clear()
    dispatch(resetTerminal())
    setCode('')
  }

  return (
    <section className={`kiosk-frame kiosk-tone-${tone}`} aria-live="polite" aria-busy={waiting}>
      <header className="kiosk-bar">
        <div className="kiosk-brand">
          <BrandLogo className="kiosk-logo" src={logo} />
          <strong>{company}</strong>
        </div>
        <p className="kiosk-clock">{clock}</p>
        <LanguageSwitcher scope="terminal" presentation="buttons" />
      </header>
      <div className="kiosk-stage">
        {showUnavailable ? (
          <>
            <p className="kiosk-mark" aria-hidden="true">!</p>
            <h1>{t('kiosk.unavailableTitle')}</h1>
            <p>{t('kiosk.unavailableBody')}</p>
          </>
        ) : null}
        {!showUnavailable && kiosk.step === 'idle' && enrolled && !replacing ? (
          <>
            <h1>{scanning ? t('kiosk.scanningTitle') : t('kiosk.idleTitle')}</h1>
            <p>{scanning ? t('kiosk.scanningBody') : t('kiosk.idleBody')}</p>
            <input
              className="kiosk-code"
              value={code}
              autoFocus
              autoComplete="off"
              autoCapitalize="characters"
              spellCheck={false}
              inputMode="text"
              enterKeyHint="done"
              aria-label={t('kiosk.code')}
              onChange={(event) => setCode(event.target.value.toUpperCase().slice(0, 16))}
              onKeyDown={(event) => {
                if (event.key === 'Enter') {
                  event.preventDefault()
                  void submitCode(code)
                }
              }}
            />
            {scanning ? (
              <div className="kiosk-actions">
                <Button type="button" onClick={() => { void submitCode(code) }}>{t('kiosk.continue')}</Button>
              </div>
            ) : (
              <div className="kiosk-actions">
                <Button type="button" variant="secondary" onClick={() => { setReplacing(true); setRegistrationCode('') }}>
                  {t('kiosk.replaceCredential')}
                </Button>
              </div>
            )}
          </>
        ) : null}
        {!showUnavailable && kiosk.step === 'idle' && (!enrolled || replacing) ? (
          <>
            <h1>{t('kiosk.enroll')}</h1>
            <p>{t('kiosk.enrollBody')}</p>
            <form className="kiosk-actions" onSubmit={(event) => { event.preventDefault(); void enroll(registrationCode) }}>
              <input
                className="kiosk-code"
                value={registrationCode}
                autoComplete="off"
                autoFocus
                aria-label={t('kiosk.registrationCode')}
                onChange={(event) => setRegistrationCode(event.target.value.trim())}
              />
              <Button type="submit">{t('kiosk.enroll')}</Button>
              {enrolled ? (
                <Button type="button" variant="secondary" onClick={() => { setReplacing(false); setRegistrationCode('') }}>
                  {t('kiosk.continue')}
                </Button>
              ) : null}
            </form>
          </>
        ) : null}
        {!showUnavailable && (kiosk.step === 'identifying' || kiosk.step === 'verifying' || kiosk.step === 'processing_attendance') ? (
          <>
            <div className="kiosk-wait" aria-hidden="true"><span /></div>
            <h1>{t(`kiosk.step.${kiosk.step}`)}</h1>
          </>
        ) : null}
        {kiosk.step === 'employee_found' && kiosk.employee ? (
          <>
            <h1>{kiosk.employee.displayName}</h1>
            <p className="kiosk-identity">{kiosk.employee.employeeNumber}</p>
            <p>{t('kiosk.confirm')}</p>
            <div className="kiosk-actions">
              <Button type="button" onClick={() => { void readFingerprint() }}>{t('kiosk.toFingerprint')}</Button>
              <Button type="button" variant="secondary" onClick={abandon}>{t('kiosk.notMe')}</Button>
            </div>
          </>
        ) : null}
        {kiosk.step === 'waiting_for_fingerprint' ? (
          <>
            <div className="kiosk-wait" aria-hidden="true"><span /></div>
            <h1>{t('kiosk.step.waiting_for_fingerprint')}</h1>
            <p>{fingerprintMode === 'mock' ? t('kiosk.mockFingerprint') : t('kiosk.bridgeFingerprint')}</p>
          </>
        ) : null}
        {kiosk.step === 'choosing_action' ? (
          <>
            <h1>{t('kiosk.choose')}</h1>
            {kiosk.employee ? <p className="kiosk-identity">{kiosk.employee.displayName}</p> : null}
            {kiosk.attendanceState ? <p>{t(`kiosk.state.${kiosk.attendanceState}`, { defaultValue: '' })}</p> : null}
            <div className="kiosk-actions">
              {actions.map((action) => (
                <Button key={action.action} type="button" onClick={() => { void choose(action.action) }}>
                  {actionLabel(action.action)}
                </Button>
              ))}
              <Button type="button" variant="secondary" onClick={abandon}>{t('kiosk.startOver')}</Button>
            </div>
          </>
        ) : null}
        {kiosk.step === 'capturing_photo' ? (
          <>
            <div className="kiosk-wait" aria-hidden="true"><span /></div>
            <h1>{t('kiosk.step.capturing_photo')}</h1>
            <p>{cameraMode === 'mock' ? t('kiosk.mockCamera') : cameraMode === 'none' ? t('kiosk.reason.device_unavailable') : t('kiosk.bridgeCamera')}</p>
          </>
        ) : null}
        {kiosk.step === 'success' ? (
          <>
            <p className="kiosk-mark" aria-hidden="true">✓</p>
            <h1>{t('kiosk.success')}</h1>
            <dl className="kiosk-result">
              <div>
                <dt>{t('kiosk.resultName')}</dt>
                <dd>{kiosk.employee?.displayName ?? ''}</dd>
              </div>
              <div>
                <dt>{t('kiosk.resultAction')}</dt>
                <dd>{kiosk.selectedAction ? actionLabel(kiosk.selectedAction) : ''}</dd>
              </div>
              <div>
                <dt>{t('kiosk.resultTime')}</dt>
                <dd>{kiosk.occurredAt ? format.dateTime(kiosk.occurredAt, zone) : ''}</dd>
              </div>
            </dl>
            <p>{t('kiosk.successBody')}</p>
          </>
        ) : null}
        {kiosk.step === 'error' && !showUnavailable ? (
          <>
            <p className="kiosk-mark" aria-hidden="true">!</p>
            <h1>{t('kiosk.errorTitle')}</h1>
            <p className="kiosk-message">{t(kiosk.messageKey ?? 'kiosk.reason.unknown')}</p>
            <div className="kiosk-actions">
              {kiosk.retry ? <Button type="button" onClick={() => { void retry() }}>{t('kiosk.retry')}</Button> : null}
              <Button type="button" variant="secondary" onClick={abandon}>{t('kiosk.startOver')}</Button>
            </div>
          </>
        ) : null}
        {kiosk.step === 'timeout' && !showUnavailable ? (
          <>
            <p className="kiosk-mark" aria-hidden="true">!</p>
            <h1>{t('kiosk.timeoutTitle')}</h1>
            <p>{t('kiosk.timeoutBody')}</p>
          </>
        ) : null}
      </div>
      {returnMs !== null && !showUnavailable ? (
        <div className="kiosk-return" aria-hidden="true" key={`${kiosk.step}-${returnMs}`}>
          <span style={{ animationDuration: `${returnMs}ms` }} />
        </div>
      ) : null}
    </section>
  )
}

function kioskTone(step: TerminalStep, offline: boolean, scanning: boolean): string {
  if (offline) {
    return 'offline'
  }
  if (scanning) {
    return 'scanning'
  }
  if (step === 'identifying' || step === 'verifying' || step === 'processing_attendance') {
    return 'processing'
  }
  if (step === 'waiting_for_fingerprint') {
    return 'fingerprint'
  }
  if (step === 'capturing_photo') {
    return 'photo'
  }
  if (step === 'employee_found' || step === 'choosing_action') {
    return 'found'
  }
  if (step === 'success' || step === 'error' || step === 'timeout') {
    return step
  }

  return 'idle'
}

function present(phase: AttemptPhase, error: unknown): FailurePresentation {
  if (error instanceof TerminalNotEnrolledError) {
    return { messageKey: 'kiosk.notEnrolled', retry: null }
  }
  if (error instanceof TerminalRequestError) {
    return failurePresentation(phase, error.code)
  }

  return failurePresentation(phase, undefined)
}
