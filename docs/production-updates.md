change this  Stock Produced element to missed opportunity on the graph and in red, add  YTD missed opportinity volume and revenue cards added on the last card. on the production add a preloader 
how do i reduce the loading time with chunks loading on the background

or start with current day (today the load the others and cache previous months + 1 week from current date to avoid loading data everytime like products, stores, machines only loads updated stocks irregadles of logged in user)

inventory:1  Failed to load resource: the server responded with a status of 504 ()
installHook.js:1 [api] Server error Object
overrideMethod @ installHook.js:1
/api/operations/production/sales?date_from=2023-01-31:1  Failed to load resource: the server responded with a status of 504 ()
installHook.js:1 [api] Server error Object
overrideMethod @ installHook.js:1
/api/operations/production/inventory?ownership=partner&per_page=500&page=1:1  Failed to load resource: the server responded with a status of 504 ()
installHook.js:1 [api] Server error Object
overrideMethod @ installHook.js:1
inventory:1  Failed to load resource: the server responded with a status of 504 ()
installHook.js:1 [api] Server error Object
overrideMethod @ installHook.js:1
/api/operations/production/sales?date_from=2023-01-31:1  Failed to load resource: the server responded with a status of 504 ()
installHook.js:1 [api] Server error Object
overrideMethod @ installHook.js:1
/api/operations/production/inventory?ownership=partner&per_page=500&page=1:1  Failed to load resource: the server responded with a status of 504 ()
installHook.js:1 [api] Server error Object
overrideMethod @ installHook.js:1
inventory:1  Failed to load resource: the server responded with a status of 504 ()
installHook.js:1 [api] Server error Object
overrideMethod @ installHook.js:1


- preloader component
Install it with npm i styled-components

import React from 'react';
import styled from 'styled-components';

const Loader = () => {
  return (
    <StyledWrapper>
      <div className="loading-wave">
        <div className="loading-bar" />
        <div className="loading-bar" />
        <div className="loading-bar" />
        <div className="loading-bar" />
      </div>
    </StyledWrapper>
  );
}

const StyledWrapper = styled.div`
  .loading-wave {
    width: 300px;
    height: 100px;
    display: flex;
    justify-content: center;
    align-items: flex-end;
  }

  .loading-bar {
    width: 20px;
    height: 10px;
    margin: 0 5px;
    background-color: #3498db;
    border-radius: 5px;
    animation: loading-wave-animation 1s ease-in-out infinite;
  }

  .loading-bar:nth-child(2) {
    animation-delay: 0.1s;
  }

  .loading-bar:nth-child(3) {
    animation-delay: 0.2s;
  }

  .loading-bar:nth-child(4) {
    animation-delay: 0.3s;
  }

  @keyframes loading-wave-animation {
    0% {
      height: 10px;
    }

    50% {
      height: 50px;
    }

    100% {
      height: 10px;
    }
  }`;

export default Loader;


