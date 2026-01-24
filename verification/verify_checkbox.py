from playwright.sync_api import sync_playwright, expect

def test_checkbox(page):
    print("Navigating to login page...")
    page.goto("http://127.0.0.1:8000/login")

    print("Waiting for checkbox...")
    # The checkbox input has type="checkbox"
    checkbox = page.locator("input[type='checkbox']")
    expect(checkbox).to_be_visible()

    print("Taking screenshot of unchecked state...")
    # Screenshot the container (parent of input)
    # The structure is label > div > input
    # But in Login.vue: label > Checkbox > div > input
    # So we find the label "Se souvenir"
    remember_label = page.locator("label").filter(has_text="Se souvenir")
    remember_label.screenshot(path="verification/checkbox_unchecked.png")

    print("Testing hover...")
    checkbox.hover()
    page.wait_for_timeout(300) # Wait for transition
    remember_label.screenshot(path="verification/checkbox_hover.png")

    print("Testing checked state...")
    checkbox.click()
    page.wait_for_timeout(300)
    remember_label.screenshot(path="verification/checkbox_checked.png")

    print("Done.")

if __name__ == "__main__":
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        page = browser.new_page()
        try:
            test_checkbox(page)
        except Exception as e:
            print(f"Error: {e}")
            page.screenshot(path="verification/error.png")
        finally:
            browser.close()
