import { Component, type ErrorInfo, type ReactNode } from 'react'
import i18n from '../shared/i18n'
import { Button } from '../shared/ui/Button'
import { Page } from '../shared/ui/Page'

type BoundaryState = { failed: boolean }

export class AppErrorBoundary extends Component<{ children: ReactNode }, BoundaryState> {
  state: BoundaryState = { failed: false }

  static getDerivedStateFromError(): BoundaryState {
    return { failed: true }
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    console.error(error.name, info.componentStack)
  }

  render() {
    if (!this.state.failed) {
      return this.props.children
    }

    return (
      <Page title={i18n.t('workspace.boundaryTitle')} intro={i18n.t('workspace.boundaryBody')}>
        <Button type="button" onClick={() => this.setState({ failed: false })}>{i18n.t('workspace.tryAgain')}</Button>
      </Page>
    )
  }
}
