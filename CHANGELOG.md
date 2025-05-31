# Changelog

### 1.0.0 (2025.05.21)
- Initial release

### 1.0.1 (2025.05.31)
- Feature: Added logic to skips any keyword whose target URL points to the same post
- Feature: Removed the global-cap check `$links_added >= SMARTY_AUL_MAX_LINKS_PER_POST … break 2;`.
    - That check stopped all further linking once any single keyword reached the post-wide limit, so other keywords were skipped.
- Feature: Per-keyword counter `$per_word` remains.
    - Each keyword still obeys its own max per post value set in the admin UI, but now reaching that limit affects only that keyword, not the rest.