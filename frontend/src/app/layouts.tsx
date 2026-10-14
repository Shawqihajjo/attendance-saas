import { useEffect, useRef, useState } from 'react'
import { NavLink, Outlet, useLocation } from 'react-router'
import { useTranslation } from 'react-i18next'
import { routesFor, type WorkspaceArea } from './access'
import { AppErrorBoundary } from './AppErrorBoundary'
import { useAppSelector } from './hooks'
import { useGetBrandingQuery } from '../features/branding/brandingApi'
import { useGetSessionQuery, useLogoutMutation } from '../features/session/sessionApi'
import { safeAssetUrl } from '../shared/theme/branding'
import { AppearanceSwitcher } from '../shared/ui/AppearanceSwitcher'
import { BrandLogo } from '../shared/ui/BrandLogo'
import { LanguageSwitcher } from '../shared/ui/LanguageSwitcher'
import { Button } from '../shared/ui/Button'
import { ThemeBinder } from './ThemeBinder'

const areas: WorkspaceArea[] = ['employee', 'manager', 'hr', 'tenant', 'platform']
const desktopNav = '(min-width: 1024px)'

export function ProvidersLayout() {
  useGetSessionQuery()
  return (
    <ThemeBinder>
      <Outlet />
    </ThemeBinder>
  )
}

export function AppShell() {
  const { t } = useTranslation()
  const location = useLocation()
  const { data } = useGetBrandingQuery()
  const session = useAppSelector((state) => state.session)
  const [logout, { isLoading }] = useLogoutMutation()
  const [menuOpen, setMenuOpen] = useState(false)
  const menuButton = useRef<HTMLButtonElement>(null)
  const sidebarRef = useRef<HTMLElement>(null)
  const name = data?.data.companyName ?? data?.data.displayName ?? t('app.name')
  const logo = safeAssetUrl(data?.data.logoUrl)
  const permissions = session.permissions

  useEffect(() => {
    setMenuOpen(false)
  }, [location.pathname])

  useEffect(() => {
    const sidebar = sidebarRef.current
    const shell = sidebar?.closest('.shell')
    const bar = sidebar?.querySelector('.sidebar-bar')
    if (!sidebar || !(shell instanceof HTMLElement) || !bar || typeof ResizeObserver === 'undefined') {
      return
    }
    const apply = () => {
      const styles = getComputedStyle(sidebar)
      const height = bar.getBoundingClientRect().height + Number.parseFloat(styles.paddingTop) + Number.parseFloat(styles.paddingBottom)
      shell.style.setProperty('--nav-bar-height', `${Math.ceil(height)}px`)
    }
    apply()
    const observer = new ResizeObserver(apply)
    observer.observe(bar)
    return () => observer.disconnect()
  }, [])

  useEffect(() => {
    if (!menuOpen) {
      return
    }
    function onKey(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        setMenuOpen(false)
        menuButton.current?.focus()
      }
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [menuOpen])

  useEffect(() => {
    if (typeof window.matchMedia !== 'function') {
      return
    }
    const query = window.matchMedia(desktopNav)
    const onChange = () => {
      if (query.matches) {
        setMenuOpen(false)
      }
    }
    query.addEventListener('change', onChange)
    return () => query.removeEventListener('change', onChange)
  }, [])

  return (
    <div className={menuOpen ? 'shell nav-open' : 'shell'}>
      <aside className="sidebar" ref={sidebarRef}>
        <div className="sidebar-bar">
          <div className="brand">
            <BrandLogo className="brand-logo" src={logo} />
            <strong title={name}>{name}</strong>
            <span>{t('app.foundation')}</span>
          </div>
          <div className="nav-toggle-wrap">
            <button
              ref={menuButton}
              type="button"
              className="nav-toggle-label"
              aria-expanded={menuOpen}
              aria-controls="app-navigation"
              onClick={() => setMenuOpen((open) => !open)}
            >
              {menuOpen ? t('nav.closeMenu') : t('nav.menu')}
            </button>
          </div>
        </div>
        <div
          className="sidebar-panel"
          id="app-navigation"
          onClick={(event) => {
            if (event.target instanceof Element && event.target.closest('a')) {
              setMenuOpen(false)
            }
          }}
        >
          <nav className="nav">
            {areas.map((area) => {
              const links = routesFor(permissions, area)
              if (links.length === 0) {
                return null
              }
              return (
                <div className="nav-group" key={area}>
                  <p className="nav-label">{t(`workspace.area.${area}`)}</p>
                  {links.map((route) => (
                    <NavLink key={route.to} to={route.to} end>{t(route.labelKey)}</NavLink>
                  ))}
                </div>
              )
            })}
            <NavLink to="/kiosk">{t('nav.kiosk')}</NavLink>
          </nav>
          <div className="sidebar-footer">
            {session.status === 'authenticated' && session.displayName ? (
              <p className="sidebar-account">{t('nav.signedIn', { name: session.displayName })}</p>
            ) : null}
            {session.status === 'authenticated' ? (
              <Button type="button" disabled={isLoading} onClick={() => { setMenuOpen(false); void logout({ platform: session.kind === 'platform' }) }}>
                {t('nav.logout')}
              </Button>
            ) : (
              <NavLink to="/login">{t('nav.login')}</NavLink>
            )}
            <LanguageSwitcher />
            <AppearanceSwitcher />
          </div>
        </div>
      </aside>
      {menuOpen ? (
        <button type="button" className="nav-backdrop" aria-label={t('nav.closeMenu')} onClick={() => { setMenuOpen(false); menuButton.current?.focus() }} />
      ) : null}
      <main className="main" inert={menuOpen ? true : undefined}>
        <AppErrorBoundary>
          <Outlet />
        </AppErrorBoundary>
      </main>
    </div>
  )
}

export function KioskLayout() {
  return (
    <div className="kiosk">
      <Outlet />
    </div>
  )
}
