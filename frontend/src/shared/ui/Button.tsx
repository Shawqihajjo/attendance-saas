import type { ButtonHTMLAttributes } from 'react'

type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: 'primary' | 'secondary'
}

export function Button({ variant = 'primary', className = '', ...props }: ButtonProps) {
  const variantClass = variant === 'secondary' ? 'secondary' : ''
  return <button className={`button ${variantClass} ${className}`.trim()} {...props} />
}
