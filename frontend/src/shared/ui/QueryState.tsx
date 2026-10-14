import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { errorMessageKey } from '../api/problems'
import { Alert } from './Alert'
import { Spinner } from './Spinner'

type QueryStateProps = {
  isLoading: boolean
  error: unknown
  empty: boolean
  emptyLabel: string
  children: ReactNode
}

export function QueryState({ isLoading, error, empty, emptyLabel, children }: QueryStateProps) {
  const { t } = useTranslation()
  if (isLoading) {
    return (
      <p className="toolbar">
        <Spinner /> {t('workspace.loading')}
      </p>
    )
  }
  if (error) {
    return <Alert>{t(errorMessageKey(error))}</Alert>
  }
  if (empty) {
    return <p className="muted">{emptyLabel}</p>
  }

  return children
}
