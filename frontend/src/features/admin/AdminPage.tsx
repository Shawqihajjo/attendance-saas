import { useTranslation } from 'react-i18next'
import { errorMessageKey } from '../../shared/api/problems'
import { Alert } from '../../shared/ui/Alert'
import { Page } from '../../shared/ui/Page'
import { Spinner } from '../../shared/ui/Spinner'
import { ConfigurationPanel } from '../branding/ConfigurationPanel'
import { useGetProfileQuery } from './profileApi'

export function AdminPage() {
  const { t } = useTranslation()
  const profile = useGetProfileQuery()

  return (
    <Page title={t('admin.title')} intro={t('admin.intro')}>
      {profile.isLoading ? (
        <p className="toolbar">
          <Spinner /> {t('admin.loading')}
        </p>
      ) : null}
      {profile.error ? <Alert>{t(errorMessageKey(profile.error))}</Alert> : null}
      {profile.data ? (
        <article className="card">
          <h2>{profile.data.data.displayName}</h2>
          <p>{profile.data.data.email}</p>
          <p className="muted">{profile.data.data.roles.map((role) => t(`workspace.role.${role}`, { defaultValue: role })).join(', ')}</p>
        </article>
      ) : null}
      <ConfigurationPanel />
    </Page>
  )
}
