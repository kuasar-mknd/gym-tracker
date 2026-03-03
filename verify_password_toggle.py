from playwright.sync_api import sync_playwright, expect

def verify_password_toggle():
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(viewport={'width': 1280, 'height': 720})
        page = context.new_page()

        print("Navigating to login page...")
        try:
            page.goto("http://localhost:8000/login")
            # Wait for the application to hydrate
            page.wait_for_load_state("networkidle")
        except Exception as e:
            print(f"Navigation failed: {e}")
            browser.close()
            return

        print(f"Page title: {page.title()}")

        try:
            print("Checking for password input...")
            # Use a more generic selector first to see if anything renders
            page.wait_for_selector('input', timeout=10000)

            password_input = page.locator('input[type="password"]')
            expect(password_input).to_be_visible(timeout=10000)

            print("Verifying initial state (type=password)...")
            expect(password_input).to_have_attribute("type", "password")

            password_input.fill("secret123")

            # Locate the toggle button by aria-label
            toggle_btn = page.locator('button[aria-label="Afficher le mot de passe"]')
            expect(toggle_btn).to_be_visible()

            print("Clicking toggle button...")
            toggle_btn.click()

            print("Verifying toggled state (type=text)...")
            # Note: The input type attribute actually changes in the DOM
            expect(password_input).to_have_attribute("type", "text")

            print("Success! Taking screenshot...")
            page.screenshot(path="verification.png")

        except Exception as e:
            print(f"Verification failed: {e}")
            page.screenshot(path="verification_failure.png")
            print("Saved failure screenshot to verification_failure.png")
            print("Page content:")
            print(page.content())

        finally:
            browser.close()

if __name__ == "__main__":
    verify_password_toggle()
