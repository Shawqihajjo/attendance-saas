import { useEffect, useState } from 'react'

export function BrandLogo({ src, className }: { src: string | null, className?: string }) {
  const [failed, setFailed] = useState(false)

  useEffect(() => {
    setFailed(false)
  }, [src])

  if (!src || failed) {
    return null
  }

  return <img className={className} src={src} alt="" onError={() => setFailed(true)} />
}
