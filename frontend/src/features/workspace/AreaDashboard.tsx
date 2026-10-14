import { NavLink } from 'react-router'
import { useTranslation } from 'react-i18next'
import { useAppSelector } from '../../app/hooks'
import { routesFor, type WorkspaceArea } from '../../app/access'

export function AreaLinks({ area, home }: { area: WorkspaceArea; home: string }) {
  const { t } = useTranslation()
  const permissions = useAppSelector((state) => state.session.permissions)
  const links = routesFor(permissions, area).filter((route) => route.to !== home)

  return (
    <>
      <p className="muted">{t('workspace.serverDecides')}</p>
      {links.length === 0 ? <p className="muted">{t('workspace.empty')}</p> : null}
      <div className="card-grid">
        {links.map((route) => (
          <NavLink className="card dashboard-link" key={route.to} to={route.to}>
            <h2>{t(route.labelKey)}</h2>
          </NavLink>
        ))}
      </div>
    </>
  )
}
