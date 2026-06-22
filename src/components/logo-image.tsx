import { useState } from "react";
import logoAsset from "@/assets/kim-fay-logo.png.asset.json";

interface LogoImageProps {
  className?: string;
  alt?: string;
}

/**
 * Renders the Kim-Fay logo with a two-stage fallback:
 *   1. Cloudflare L5E asset URL  (works on the deployed Worker)
 *   2. /kim-fay-logo.png          (works when the file is placed in public/)
 *   3. "KF" styled text avatar    (always works as last resort)
 */
export function LogoImage({ className = "h-7 w-7 object-contain", alt = "Kim-Fay" }: LogoImageProps) {
  const [src, setSrc] = useState<string>(logoAsset.url);
  const [failed, setFailed] = useState(false);

  function handleError() {
    if (src === logoAsset.url) {
      setSrc("/kim-fay-logo.png");
    } else {
      setFailed(true);
    }
  }

  if (failed) {
    return (
      <span className="flex items-center justify-center h-full w-full text-[11px] font-extrabold tracking-tight text-[#1a4480] select-none">
        KF
      </span>
    );
  }

  return <img src={src} alt={alt} className={className} onError={handleError} />;
}
