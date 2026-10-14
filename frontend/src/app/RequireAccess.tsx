import type { ReactNode } from 'react'
import { Navigate, useLocation } from 'react-router'
import { useTranslation } from 'react-i18next'
import { useAppSelector } from './hooks'
import { Page } from '../shared/ui/Page'
import { Spinner } from '../shared/ui/Spinner'

export function RequireAccess({ permission, children }: { permission: string; children: ReactNode }) {
  const { t } = useTranslation()
  const location = useLocation()
  const session = useAppSelector((state) => state.session)

  if (session.status === 'unknown') {
    return (
      <p className="toolbar">
        <Spinner /> {t('workspace.checkingSession')}
      </p>
    )
  }
  if (session.status !== 'authenticated') {
    const next = encodeURIComponent(location.pathname)
    return <Navigate to={`/login?next=${next}`} replace />
  }
  if (!session.permissions.includes(permission)) {
    return (
      <Page title={t('workspace.forbiddenTitle')} intro={t('workspace.forbiddenBody')}>
        <p className="muted">{t('workspace.forbiddenDetail')}</p>
      </Page>
    )
  }

  return children
}
