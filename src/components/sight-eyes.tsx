/**
 * Animated eyes mark for Kim-Fay Sight —
 * "Sight" means sees every business procedure.
 */
export function SightEyes({
  className = "",
  size = 120,
}: {
  className?: string;
  size?: number;
}) {
  const id = "sight-eyes";
  return (
    <svg
      width={size}
      height={size * 0.55}
      viewBox="0 0 200 110"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
      className={className}
      role="img"
      aria-label="Kim-Fay Sight — sees every business procedure"
    >
      <title>Kim-Fay Sight</title>
      <defs>
        <radialGradient id={`${id}-iris`} cx="40%" cy="35%" r="65%">
          <stop offset="0%" stopColor="#7eb6ff" />
          <stop offset="55%" stopColor="#2f6fd6" />
          <stop offset="100%" stopColor="#163d7a" />
        </radialGradient>
        <filter id={`${id}-glow`} x="-40%" y="-40%" width="180%" height="180%">
          <feGaussianBlur stdDeviation="3" result="blur" />
          <feMerge>
            <feMergeNode in="blur" />
            <feMergeNode in="SourceGraphic" />
          </feMerge>
        </filter>
        <style>{`
          @keyframes sight-blink {
            0%, 88%, 100% { transform: scaleY(1); }
            92%, 96% { transform: scaleY(0.08); }
          }
          @keyframes sight-look {
            0%, 100% { transform: translate(0, 0); }
            25% { transform: translate(3px, -1px); }
            50% { transform: translate(-2px, 1px); }
            75% { transform: translate(2px, 1px); }
          }
          .sight-lid {
            transform-origin: center;
            transform-box: fill-box;
            animation: sight-blink 4.5s ease-in-out infinite;
          }
          .sight-lid-right {
            animation-delay: 0.12s;
          }
          .sight-gaze {
            animation: sight-look 6s ease-in-out infinite;
          }
        `}</style>
      </defs>

      {/* Soft ambient rings */}
      <ellipse cx="60" cy="55" rx="42" ry="28" fill="white" fillOpacity="0.06" />
      <ellipse cx="140" cy="55" rx="42" ry="28" fill="white" fillOpacity="0.06" />

      {/* Left eye */}
      <g className="sight-lid" filter={`url(#${id}-glow)`}>
        <ellipse cx="60" cy="55" rx="36" ry="24" fill="white" fillOpacity="0.95" />
        <ellipse cx="60" cy="55" rx="36" ry="24" stroke="white" strokeOpacity="0.35" strokeWidth="1.5" />
        <g className="sight-gaze">
          <circle cx="60" cy="55" r="14" fill={`url(#${id}-iris)`} />
          <circle className="sight-pupil" cx="60" cy="55" r="7.5" fill="#0b1f3d" />
          <circle cx="55" cy="50" r="3.5" fill="white" fillOpacity="0.9" />
          <circle cx="64" cy="58" r="1.4" fill="white" fillOpacity="0.45" />
        </g>
      </g>

      {/* Right eye */}
      <g className="sight-lid sight-lid-right" filter={`url(#${id}-glow)`}>
        <ellipse cx="140" cy="55" rx="36" ry="24" fill="white" fillOpacity="0.95" />
        <ellipse cx="140" cy="55" rx="36" ry="24" stroke="white" strokeOpacity="0.35" strokeWidth="1.5" />
        <g className="sight-gaze">
          <circle cx="140" cy="55" r="14" fill={`url(#${id}-iris)`} />
          <circle className="sight-pupil" cx="140" cy="55" r="7.5" fill="#0b1f3d" />
          <circle cx="135" cy="50" r="3.5" fill="white" fillOpacity="0.9" />
          <circle cx="144" cy="58" r="1.4" fill="white" fillOpacity="0.45" />
        </g>
      </g>
    </svg>
  );
}

/** Compact pair for mobile / form header. */
export function SightEyesMark({ className = "" }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 48 24"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
      className={className}
      aria-hidden
    >
      <defs>
        <style>{`
          @keyframes sight-blink-sm {
            0%, 88%, 100% { transform: scaleY(1); }
            92%, 96% { transform: scaleY(0.1); }
          }
          .sight-lid-sm {
            transform-origin: center;
            transform-box: fill-box;
            animation: sight-blink-sm 4.5s ease-in-out infinite;
          }
        `}</style>
      </defs>
      <g className="sight-lid-sm">
        <ellipse cx="12" cy="12" rx="10" ry="7" fill="currentColor" fillOpacity="0.12" />
        <ellipse cx="12" cy="12" rx="10" ry="7" stroke="currentColor" strokeOpacity="0.35" strokeWidth="1" />
        <circle cx="12" cy="12" r="4" fill="currentColor" fillOpacity="0.85" />
        <circle cx="12" cy="12" r="2" fill="currentColor" />
        <circle cx="10.5" cy="10.5" r="0.9" fill="white" fillOpacity="0.9" />
      </g>
      <g className="sight-lid-sm" style={{ animationDelay: "0.12s" }}>
        <ellipse cx="36" cy="12" rx="10" ry="7" fill="currentColor" fillOpacity="0.12" />
        <ellipse cx="36" cy="12" rx="10" ry="7" stroke="currentColor" strokeOpacity="0.35" strokeWidth="1" />
        <circle cx="36" cy="12" r="4" fill="currentColor" fillOpacity="0.85" />
        <circle cx="36" cy="12" r="2" fill="currentColor" />
        <circle cx="34.5" cy="10.5" r="0.9" fill="white" fillOpacity="0.9" />
      </g>
    </svg>
  );
}
