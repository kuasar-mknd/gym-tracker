## 2024-03-25 - Login Form Elements Covered by Keyboard on Small Viewports
**Vulnerability:** When running Dusk tests with `login-failure-resizeToIphoneMini.png` missing some form fields, it happens when the elements are cut off on small viewports and we attempt to click or type into them.
**Learning:** We need to aggressively use `scrollIntoView()` via JS on `data-testid="email-input"` or others if it falls out of the bounding box before interacting with them, particularly on the Mini (375x812).
**Prevention:** In Dusk tests specifically targeting iPhone Mini, always use `pause(500)` after `type` and perform `$browser->script("document.querySelector('...').scrollIntoView({block: 'center'});")` if elements might be pushed off-screen.
