import styled from "styled-components";

const Wrapper = styled.div`
  display: flex;
  min-height: 7rem;
  width: 100%;
  align-items: center;
  justify-content: center;

  .loading-wave {
    width: 180px;
    height: 64px;
    display: flex;
    justify-content: center;
    align-items: flex-end;
  }

  .loading-bar {
    width: 14px;
    height: 8px;
    margin: 0 4px;
    background-color: #0874d1;
    border-radius: 5px;
    animation: production-loading-wave 1s ease-in-out infinite;
  }

  .loading-bar:nth-child(2) { animation-delay: 0.1s; }
  .loading-bar:nth-child(3) { animation-delay: 0.2s; }
  .loading-bar:nth-child(4) { animation-delay: 0.3s; }

  @keyframes production-loading-wave {
    0%, 100% { height: 8px; }
    50% { height: 44px; }
  }

  @media (prefers-reduced-motion: reduce) {
    .loading-bar { animation: none; height: 20px; }
  }
`;

export function ProductionPreloader() {
  return (
    <Wrapper role="status" aria-live="polite" aria-label="Loading production data">
      <div>
        <div className="loading-wave" aria-hidden="true">
          <div className="loading-bar" />
          <div className="loading-bar" />
          <div className="loading-bar" />
          <div className="loading-bar" />
        </div>
        <p className="text-center text-xs font-medium text-muted-foreground">Loading current stock…</p>
      </div>
    </Wrapper>
  );
}
