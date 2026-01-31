from playwright.sync_api import sync_playwright, expect

def verify_workouts_page():
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        page = browser.new_page()

        try:
            # Login
            print("Navigating to login...")
            page.goto("http://localhost:8000/login")
            page.fill('input[type="email"]', "test@example.com")
            page.fill('input[type="password"]', "password")
            page.click('button[type="submit"]')

            # Wait for dashboard
            print("Waiting for dashboard...")
            page.wait_for_url("**/dashboard")

            # Go to Workouts
            print("Navigating to workouts...")
            page.goto("http://localhost:8000/workouts")

            # Wait for "Exercices disponibles"
            print("Waiting for exercises list...")
            expect(page.get_by_text("Exercices disponibles")).to_be_visible()

            # Wait for specific exercise to ensure data loaded
            print("Waiting for 'Développé Couché'...")
            expect(page.get_by_text("Développé Couché")).to_be_visible()

            print("Taking screenshot...")
            page.screenshot(path="verification.png", full_page=True)
            print("Verification complete.")

        except Exception as e:
            print(f"Error: {e}")
            page.screenshot(path="error.png")
            raise e
        finally:
            browser.close()

if __name__ == "__main__":
    verify_workouts_page()
