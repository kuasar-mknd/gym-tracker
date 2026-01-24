from playwright.sync_api import sync_playwright, expect
import os

def run():
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context()
        page = context.new_page()

        try:
            # Login
            print("Navigating to login...")
            page.goto("http://127.0.0.1:8000/login")

            print("Filling login form...")
            page.fill("input[type='email']", "test@example.com")
            page.fill("input[type='password']", "password")

            print("Clicking login...")
            page.click("button:has-text('Se connecter')")

            # Wait for dashboard
            print("Waiting for dashboard...")
            page.wait_for_url("**/dashboard")

            # Navigate to sleep tools
            print("Navigating to sleep tracker...")
            page.goto("http://127.0.0.1:8000/tools/sleep")

            # Check title
            expect(page.get_by_role("heading", name="Sleep Tracker")).to_be_visible()

            # Add a log
            print("Adding sleep log...")

            # Change duration to 7h 30m
            page.fill("input[placeholder='Hours']", "7")
            page.fill("input[placeholder='Mins']", "30")

            # Click 4th star (index 3)
            page.locator("button:has-text('★')").nth(3).click()

            # Fill notes
            page.fill("textarea", "Feeling rested.")

            # Click "Log Sleep"
            page.click("button:has-text('Log Sleep')")

            # Verify it appears in list
            print("Verifying log...")
            expect(page.get_by_text("Feeling rested.")).to_be_visible()

            # Use .first() to avoid strict mode violation if multiple elements exist (stats, list, etc)
            expect(page.get_by_text("7h 30m").first).to_be_visible()

            print("Taking screenshot...")
            os.makedirs("/home/jules/verification", exist_ok=True)
            page.screenshot(path="/home/jules/verification/sleep_tracker.png", full_page=True)
            print("Done.")

        except Exception as e:
            print(f"Error: {e}")
            os.makedirs("/home/jules/verification", exist_ok=True)
            page.screenshot(path="/home/jules/verification/sleep_tracker_error.png", full_page=True)
            raise e
        finally:
            browser.close()

if __name__ == "__main__":
    run()
