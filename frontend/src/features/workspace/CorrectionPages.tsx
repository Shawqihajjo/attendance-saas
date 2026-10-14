import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { useAppSelector } from '../../app/hooks'
import { correctionRequest } from '../../shared/forms/validate'
import { errorMessageKey } from '../../shared/api/problems'
import { Alert } from '../../shared/ui/Alert'
import { Button } from '../../shared/ui/Button'
import { Page } from '../../shared/ui/Page'
import { QueryState } from '../../shared/ui/QueryState'
import { Pager } from './ReportControls'
import {
  useDecideCorrectionMutation,
  useGetCorrectionsQuery,
  useGetMyCorrectionsQuery,
  useRequestCorrectionMutation,
  type CorrectionRecord,
} from './workspaceApi'

const actions = ['clock_in', 'clock_out', 'lunch_start', 'lunch_end'] as const

function isOpen(status: string): boolean {
  return status === 'requested' || status === 'pending'
}

function CorrectionCard({ record, canDecide }: { record: CorrectionRecord; canDecide: boolean }) {
  const { t } = useTranslation()
  const [decide, state] = useDecideCorrectionMutation()
  const [comment, setComment] = useState('')
  const open = canDecide && isOpen(record.status)

  return (
    <article className="card">
      <h2>{t(`workspace.correctionStatus.${record.status}`, { defaultValue: record.status })}</h2>
      {record.employeeName ? <p>{record.employeeName}</p> : null}
      <p>{record.localDate} {record.localTime}</p>
      {record.requestedAction ? <p>{t(`attendance.${record.requestedAction}`, { defaultValue: record.requestedAction })}</p> : null}
      {record.requestedOccurredAt ? <p className="muted">{t('workspace.utcTime')} {record.requestedOccurredAt}</p> : null}
      <p>{record.reason}</p>
      {record.comment ? <p>{record.comment}</p> : null}
      {record.supportingInformation ? <p>{record.supportingInformation}</p> : null}
      {record.originalEvents.length > 0 ? (
        <div>
          <p className="muted">{t('workspace.originalAttendance')}</p>
          <ul>
            {record.originalEvents.map((event) => (
              <li key={event.id}>{t(`attendance.${event.eventType}`, { defaultValue: event.eventType })} · {event.occurredAt}</li>
            ))}
          </ul>
        </div>
      ) : null}
      <p className="muted">{record.createdAt}</p>
      {record.decidedAt ? <p className="muted">{record.decidedAt}</p> : null}
      {record.decisionComment ? <p>{record.decisionComment}</p> : null}
      {open ? (
        <>
          <label className="field">
            {t('workspace.decisionComment')}
            <textarea value={comment} maxLength={500} onChange={(event) => setComment(event.target.value)} />
          </label>
          <div className="toolbar">
            <Button type="button" disabled={state.isLoading} onClick={() => { void decide({ id: record.id, decision: 'approved', comment: comment.trim() }) }}>{t('workspace.approve')}</Button>
            <Button type="button" variant="secondary" disabled={state.isLoading} onClick={() => { void decide({ id: record.id, decision: 'rejected', comment: comment.trim() }) }}>{t('workspace.reject')}</Button>
          </div>
        </>
      ) : null}
      {state.error ? <Alert>{t(errorMessageKey(state.error))}</Alert> : null}
    </article>
  )
}

function CorrectionList({ records, canDecide }: { records: CorrectionRecord[]; canDecide: boolean }) {
  return (
    <div className="card-grid">
      {records.map((record) => (
        <CorrectionCard key={record.id} record={record} canDecide={canDecide} />
      ))}
    </div>
  )
}

export function MyCorrectionPage() {
  const { t } = useTranslation()
  const [offset, setOffset] = useState(0)
  const corrections = useGetMyCorrectionsQuery({ limit: 25, offset })
  const [request, requestState] = useRequestCorrectionMutation()
  const [localDate, setLocalDate] = useState('')
  const [localTime, setLocalTime] = useState('')
  const [action, setAction] = useState('')
  const [reason, setReason] = useState('')
  const [comment, setComment] = useState('')
  const [supportingInformation, setSupportingInformation] = useState('')
  const [fieldError, setFieldError] = useState<string | null>(null)
  const records = corrections.data?.data ?? []

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    const invalid = correctionRequest({ localDate, localTime, action, reason, comment, supportingInformation })
    setFieldError(invalid)
    if (invalid) {
      return
    }
    try {
      await request({
        localDate,
        localTime,
        action,
        reason: reason.trim(),
        comment: comment.trim(),
        supportingInformation: supportingInformation.trim(),
      }).unwrap()
      setLocalDate('')
      setLocalTime('')
      setAction('')
      setReason('')
      setComment('')
      setSupportingInformation('')
    } catch {
      setFieldError(null)
    }
  }

  return (
    <Page title={t('workspace.myCorrection')} intro={t('workspace.myCorrectionIntro')}>
      <form className="panel" onSubmit={(event) => { void onSubmit(event) }}>
        <label className="field">
          {t('workspace.correctionDate')}
          <input type="date" value={localDate} onChange={(event) => setLocalDate(event.target.value)} />
        </label>
        <label className="field">
          {t('workspace.correctionTime')}
          <input type="time" value={localTime} onChange={(event) => setLocalTime(event.target.value)} />
        </label>
        <label className="field">
          {t('workspace.correctionAction')}
          <select value={action} onChange={(event) => setAction(event.target.value)}>
            <option value="">{t('workspace.chooseAction')}</option>
            {actions.map((item) => (
              <option key={item} value={item}>{t(`attendance.${item}`)}</option>
            ))}
          </select>
        </label>
        <label className="field">
          {t('workspace.reason')}
          <textarea value={reason} maxLength={500} onChange={(event) => setReason(event.target.value)} />
        </label>
        <label className="field">
          {t('workspace.comment')}
          <textarea value={comment} maxLength={500} onChange={(event) => setComment(event.target.value)} />
        </label>
        <label className="field">
          {t('workspace.supportingInformation')}
          <textarea value={supportingInformation} maxLength={1000} onChange={(event) => setSupportingInformation(event.target.value)} />
        </label>
        {fieldError ? <Alert>{t(fieldError)}</Alert> : null}
        {requestState.error ? <Alert>{t(errorMessageKey(requestState.error))}</Alert> : null}
        <Button type="submit" disabled={requestState.isLoading}>{t('workspace.sendRequest')}</Button>
      </form>
      <QueryState isLoading={corrections.isLoading} error={corrections.error} empty={records.length === 0} emptyLabel={t('workspace.noCorrections')}>
        <CorrectionList records={records} canDecide={false} />
      </QueryState>
      {corrections.data?.meta.page ? <Pager page={corrections.data.meta.page} onOffset={setOffset} /> : null}
    </Page>
  )
}

export function ReviewCorrectionsPage() {
  const { t } = useTranslation()
  const permissions = useAppSelector((state) => state.session.permissions)
  const [offset, setOffset] = useState(0)
  const corrections = useGetCorrectionsQuery({ limit: 25, offset })
  const records = corrections.data?.data ?? []
  const canDecide = permissions.includes('attendance.correction.decide') || permissions.includes('attendance.correction.manage')

  return (
    <Page title={t('workspace.reviewCorrections')} intro={t('workspace.reviewCorrectionsIntro')}>
      <QueryState isLoading={corrections.isLoading} error={corrections.error} empty={records.length === 0} emptyLabel={t('workspace.noCorrections')}>
        <CorrectionList records={records} canDecide={canDecide} />
      </QueryState>
      {corrections.data?.meta.page ? <Pager page={corrections.data.meta.page} onOffset={setOffset} /> : null}
    </Page>
  )
}
