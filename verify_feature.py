from playwright.sync_api import sync_playwright

def verify_feature(page):
    page.goto("http://127.0.0.1:8000/login")
    page.wait_for_timeout(2000)

    # Dump the page content if login form is not found
    try:
        page.wait_for_selector('input[type="email"]', timeout=5000)
    except Exception as e:
        print("Login form not found. Dumping page content:")
        print(page.content()[:1000])
        raise e

    # Login
    page.locator('input[type="email"]').fill('test@example.com')
    page.locator('input[type="password"]').fill('password')
    page.locator('button[type="submit"]').click()
    page.wait_for_timeout(3000)

    # Go to Workouts page
    page.goto("http://127.0.0.1:8000/workouts")
    page.wait_for_timeout(2000)

    page.wait_for_selector('h3:has-text("Exercices disponibles")')
    page.wait_for_timeout(2000)

    # Take screenshot
    page.screenshot(path="/home/jules/verification/verification.png")
    page.wait_for_timeout(1000)

if __name__ == "__main__":
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(record_video_dir="/home/jules/verification/video")
        page = context.new_page()
        try:
            verify_feature(page)
        finally:
            context.close()
            browser.close()
