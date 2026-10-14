import type { ReactNode } from 'react'

type PageProps = {
  title: string
  intro?: string
  actions?: ReactNode
  children: ReactNode
}

export function Page({ title, intro, actions, children }: PageProps) {
  return (
    <section className="page">
      <header className="page-header">
        <div>
          <h1>{title}</h1>
          {intro ? <p className="muted">{intro}</p> : null}
        </div>
        {actions}
      </header>
      {children}
    </section>
  )
}
