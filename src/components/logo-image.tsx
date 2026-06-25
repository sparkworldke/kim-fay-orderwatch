import { useState } from "react";
import logoAsset from "@/assets/kim-fay-logo.png.asset.json";

interface LogoImageProps {
  className?: string;
  alt?: string;
  /** Show text fallback ("KF") instead of the image — for icon-only collapsed states */
  iconOnly?: boolean;
}

/**
 * Renders the Kim-Fay logo with a two-stage fallback:
 *   1. Cloudflare L5E asset URL  (works on the deployed Worker)
 *   2. /kim-fay-logo.png          (works when the file is placed in public/)
 *   3. "KF" styled text avatar    (always works as last resort)
 */
const LOCAL_LOGO = "/kim-fay-logo.png";

export function LogoImage({
  className = "h-auto w-28 object-contain",
  alt = "Kim-Fay",
  iconOnly = false,
}: LogoImageProps) {
  // L5E asset URLs only exist on the deployed Worker — use public/ locally.
  const initialSrc = import.meta.env.DEV ? LOCAL_LOGO : logoAsset.url;
  const [src, setSrc] = useState<string>(initialSrc);
  const [failed, setFailed] = useState(false);

  function handleError() {
    if (src !== LOCAL_LOGO) {
      setSrc(LOCAL_LOGO);
    } else {
      setFailed(true);
    }
  }

  if (failed || iconOnly) {
    return (
      <span className="flex items-center justify-center h-full w-full text-[11px] font-extrabold tracking-tight text-[#1a4480] select-none">
        KF
      </span>
    );
  }

  return <img src={src} alt={alt} className={className} onError={handleError} />;
}
