## Description
<!-- Describe the changes introduced by this PR -->

## Related Issue
<!-- Link to the Jira ticket or GitHub issue -->
Ticket: [PRE-XXXX](https://payplug.atlassian.net/browse/PRE-XXXX)

## Type of Change
<!-- At least one required — replace [ ] with [x] -->
[ ] 🐛 Bug fix
[ ] ✨ New feature
[ ] 💥 Breaking change
[ ] ♻️ Refactor
[ ] 🔧 Configuration / CI
[ ] 🚀 Release (`release/*` branch targeting `main`)
[ ] 📦 Dependency update
[ ] 🔒 Security fix
[ ] 📝 Documentation update

---

## ✅ Quality Checklist

### Local Environment & Hooks
- [ ] Local Git hooks (**CaptainHook**) are installed and executed cleanly (`make install`).
- [ ] Commit messages strictly follow the `(PRE|SMP)-XXXX: description` pattern.
- [ ] Branch name follows `(feature|fix|hotfix|refactor)/(PRE|SMP)-XXXX...` or `(release|patch)/x.y.z`.

### Testing & Code Quality
- [ ] Coding style rules have been applied locally (`make cs-fix`).
- [ ] Static analysis passes with no new regressions (`make stan` — PHPStan level 8).
- [ ] I have added/updated PHPUnit tests if applicable (`make test`).
- [ ] No PHP syntax newer than 7.1 introduced in `src/` or `tests/` (no typed properties, arrow
  functions, constructor property promotion, `match`, `enum`).

### CI/CD Deployment Context
- [ ] The CI pipeline passes fully on GitHub, including the `compatibility` matrix
  (PHP 7.1 / 7.4 / 8.0 / 8.1 / 8.2) and the `quality` job.
- [ ] **For Release Branches:** If this is a `release/*` branch, I am targeting the correct base
  branch (`main`) to allow the automated `apply-release` version bumping job to run.

---

## Notes for Reviewer
<!-- Anything specific the reviewer should pay attention to -->

