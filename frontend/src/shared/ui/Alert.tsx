type AlertProps = {
  children: string
  tone?: 'danger' | 'info'
}

export function Alert({ children, tone = 'danger' }: AlertProps) {
  return <div className={tone === 'info' ? 'alert info' : 'alert'} role="alert">{children}</div>
}
