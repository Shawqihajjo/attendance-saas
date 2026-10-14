import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { persistAppearance, readAppearance, type Appearance } from '../theme/branding'

const choices: Appearance[] = ['light', 'dark', 'system']

export function AppearanceSwitcher() {
  const { t } = useTranslation()
  const [appearance, setAppearance] = useState<Appearance>(readAppearance)

  return (
    <label className="toolbar">
      <span className="muted">{t('appearance.label')}</span>
      <select
        value={appearance}
        onChange={(event) => {
          const next = event.target.value as Appearance
          setAppearance(next)
          persistAppearance(next)
        }}
      >
        {choices.map((choice) => (
          <option key={choice} value={choice}>
            {t(`appearance.${choice}`)}
          </option>
        ))}
      </select>
    </label>
  )
}
