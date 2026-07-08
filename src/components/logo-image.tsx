interface LogoImageProps {
  className?: string;
  alt?: string;
  /** Show text fallback ("KF") instead of the image — for icon-only collapsed states */
  iconOnly?: boolean;
}

/** Public asset — copied to dist/client on build and served by the Worker. */
const LOGO_SRC = "/kim-fay-logo.png";

export function LogoImage({
  className = "h-auto w-28 object-contain",
  alt = "Kim-Fay",
  iconOnly = false,
}: LogoImageProps) {
  if (iconOnly) {
    return (
      <span className="flex h-full w-full select-none items-center justify-center text-[11px] font-extrabold tracking-tight text-[#1a4480]">
        KF
      </span>
    );
  }

  return <img src={LOGO_SRC} alt={alt} className={className} />;
}