import { useTranslation } from 'react-i18next'

export function Spinner() {
  const { t } = useTranslation()
  return <span className="spinner" role="status" aria-label={t('common.loading')} />
}
