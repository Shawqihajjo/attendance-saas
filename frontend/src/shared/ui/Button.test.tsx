import { render, screen } from '@testing-library/react'
import { Button } from './Button'

describe('Button', () => {
  it('renders its label', () => {
    render(<Button>Continue</Button>)
    expect(screen.getByRole('button', { name: 'Continue' })).toBeTruthy()
  })
})
