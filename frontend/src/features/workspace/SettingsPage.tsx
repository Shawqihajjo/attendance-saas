import { useTranslation } from 'react-i18next'
import { ConfigurationPanel } from '../branding/ConfigurationPanel'
import { Page } from '../../shared/ui/Page'

export function SettingsPage({ section, titleKey }: { section: 'branding' | 'languages' | 'timezone' | 'settings'; titleKey: string }) {
  const { t } = useTranslation()

  return (
    <Page title={t(titleKey)} intro={t('workspace.settingsIntro')}>
      <ConfigurationPanel section={section} />
    </Page>
  )
}
