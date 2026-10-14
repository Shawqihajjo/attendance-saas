import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { Provider } from 'react-redux'
import { RouterProvider } from 'react-router'
import { store } from './app/store'
import { router } from './app/router'
import './shared/i18n'
import './index.css'

const root = document.getElementById('root')
if (!root) {
  throw new Error('Root element is missing.')
}

createRoot(root).render(
  <StrictMode>
    <Provider store={store}>
      <RouterProvider router={router} />
    </Provider>
  </StrictMode>,
)
