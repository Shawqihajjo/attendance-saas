import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Button } from './Button'

export function ConfirmDialog({
  open,
  title,
  body,
  confirmLabel,
  busy = false,
  confirmDisabled = false,
  children,
  onConfirm,
  onCancel,
}: {
  open: boolean
  title: string
  body: string
  confirmLabel: string
  busy?: boolean
  confirmDisabled?: boolean
  children?: ReactNode
  onConfirm: () => void
  onCancel: () => void
}) {
  const { t } = useTranslation()
  if (!open) {
    return null
  }

  return (
    <div className="dialog-backdrop" role="presentation" onClick={onCancel}>
      <div
        className="dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="confirm-title"
        onClick={(event) => event.stopPropagation()}
      >
        <h2 id="confirm-title">{title}</h2>
        <p>{body}</p>
        {children}
        <div className="toolbar">
          <Button type="button" disabled={busy || confirmDisabled} onClick={onConfirm}>{confirmLabel}</Button>
          <Button type="button" variant="secondary" onClick={onCancel}>{t('confirm.cancel')}</Button>
        </div>
      </div>
    </div>
  )
}
