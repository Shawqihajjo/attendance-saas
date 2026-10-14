import { useState, type FormEvent } from 'react'
import { useNavigate, useSearchParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { landingPath, safeNextPath } from '../../app/access'
import { useAppSelector } from '../../app/hooks'
import { errorMessageKey } from '../../shared/api/problems'
import { Alert } from '../../shared/ui/Alert'
import { Button } from '../../shared/ui/Button'
import { Page } from '../../shared/ui/Page'
import { useLoginMutation } from './sessionApi'

export function LoginPage() {
  const { t } = useTranslation()
  const session = useAppSelector((state) => state.session)
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const [login, { isLoading }] = useLoginMutation()
  const [notice, setNotice] = useState<string | null>(null)
  const [failed, setFailed] = useState(false)

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    const email = String(form.get('email') ?? '')
    const password = String(form.get('password') ?? '')
    setFailed(false)
    setNotice(null)
    try {
      const context = await fetch('/api/v1/tenant/context', { credentials: 'include' })
      if (!context.ok && context.status !== 404) {
        setFailed(true)
        setNotice(t(errorMessageKey({ data: await context.json() })))
        return
      }
      const result = await login({ email, password, platform: context.status === 404 }).unwrap()
      setNotice(t('auth.authenticated', { name: result.data.displayName ?? result.data.principal ?? '' }))
      navigate(safeNextPath(params.get('next')) ?? landingPath(result.data.permissions, result.data.kind))
    } catch (error) {
      setFailed(true)
      setNotice(t(errorMessageKey(error)))
    }
  }

  const signedIn = session.status === 'authenticated'
    ? t('auth.authenticated', { name: session.displayName ?? '' })
    : t('auth.anonymous')

  return (
    <Page title={t('auth.title')} intro={t('auth.intro')}>
      {notice ? <Alert tone={failed ? 'danger' : 'info'}>{notice}</Alert> : null}
      <form className="panel" onSubmit={onSubmit}>
        <label className="field">
          {t('auth.email')}
          <input name="email" type="email" autoComplete="username" required />
        </label>
        <label className="field">
          {t('auth.password')}
          <input name="password" type="password" autoComplete="current-password" required />
        </label>
        <Button type="submit" disabled={isLoading}>{isLoading ? t('auth.working') : t('auth.submit')}</Button>
      </form>
      <p className="muted">{signedIn}</p>
    </Page>
  )
}
