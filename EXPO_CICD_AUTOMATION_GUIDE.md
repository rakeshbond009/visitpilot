# Expo Smart CI/CD Automation Guide
## The "VisitPilot" Blueprint for Zero-Frustration Updates

This guide documents the high-performance CI/CD pipeline built for the VisitPilot project. It enables **Instant OTA Updates** for UI changes and **Automated APK Builds** for native changes, all running locally on GitHub to save costs.

---

### 1. The Core Concepts

#### **A. The "Frequency" (Runtime Version)**
Updates and APKs only talk to each other if they are on the same "frequency." In Expo, this is the `runtimeVersion`.
*   **Always** set a fixed string like `"1.0.0"` in `app.json`.
*   **Never** leave it to be automatically generated, or your updates will show "No deployments for this runtime."

#### **B. The "Logic" (Path Filtering)**
We use `dorny/paths-filter` to detect what changed:
*   **JS/CSS changes** $\rightarrow$ Trigger ONLY a **1-minute OTA Update**.
*   **`app.json`, `android/`, `package.json`** $\rightarrow$ Trigger a **Full APK Build**.

---

### 2. The Configuration Files

#### **`app.json` (The Setup)**
Ensure these fields are locked in:
```json
{
  "expo": {
    "version": "1.0.0",
    "runtimeVersion": "1.0.0",
    "updates": {
       "url": "https://u.expo.dev/YOUR-PROJECT-ID"
    },
    "android": {
      "versionCode": 1,
      "adaptiveIcon": {
        "foregroundImage": "./assets/icon.png",
        "backgroundColor": "#ffffff"
      }
    }
  }
}
```

#### **`eas.json` (The Channels)**
Explicitly define channels to map updates to builds:
```json
{
  "build": {
    "preview": {
      "channel": "preview",
      "distribution": "internal",
      "android": { "buildType": "apk" }
    }
  }
}
```

---

### 3. The GitHub Actions Workflow (`.github/workflows/main.yml`)

Copy this structure for any new project:

```yaml
name: CI/CD Automation
on:
  push:
    branches: [main]

jobs:
  check_changes:
    runs-on: ubuntu-latest
    outputs:
      native: ${{ steps.filter.outputs.native }}
    steps:
      - uses: actions/checkout@v4
      - uses: dorny/paths-filter@v3
        id: filter
        with:
          filters: |
            native:
              - 'mobile_app/android/**'
              - 'mobile_app/app.json'
              - 'mobile_app/package.json'

  update:
    name: Instant OTA Update
    needs: check_changes
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: expo/expo-github-action@v8
        with:
          eas-version: latest
          token: ${{ secrets.EXPO_TOKEN }}
      - name: Install & Publish
        run: |
          cd mobile_app
          npm install
          # The key: Force Link the channel to the branch
          npx eas-cli channel:edit preview --branch preview --non-interactive || npx eas-cli channel:create preview --branch preview --non-interactive
          npx eas-cli update --branch preview --message "Auto-Sync" --non-interactive

  build:
    name: Local APK Build
    needs: check_changes
    if: needs.check_changes.outputs.native == 'true'
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: expo/expo-github-action@v8
        with:
          eas-version: latest
          token: ${{ secrets.EXPO_TOKEN }}
      - name: Build Locally on GitHub
        run: |
          cd mobile_app
          npm install
          # --local saves Expo credits and avoids queues
          eas build --platform android --profile preview --non-interactive --local
```

---

### 4. Implementation Checklist for New Projects

1.  **EAS Init:** Run `eas build:configure` in your new project.
2.  **Secrets:** Add `EXPO_TOKEN` to your GitHub Repository Secrets.
3.  **App Config:** Set a hardcoded `runtimeVersion` in `app.json`.
4.  **Action:** Create the `.github/workflows/main.yml` file.
5.  **First Build:** Run the build once manually to ensure your keystores/credentials are initialized on Expo.

---

### 5. Troubleshooting "Updates Not Showing"
*   **Check Runtime:** Does `app.json` on the Update match `app.json` in the binary?
*   **Check Channel:** Is the `preview` channel pointing to the `preview` branch? (Refresh using the "Auto-Linker" command in step 3).
*   **Restart App:** Sometimes the app needs to be closed and re-opened **twice** to download and then apply the update.

---

> [!TIP]
> **Why use `--local`?**
> Standard `eas build` runs on Expo's expensive cloud. Using `--local` inside a GitHub Action runs the build on GitHub's free (or cheaper) runner, saving you money and skipping the queue!
