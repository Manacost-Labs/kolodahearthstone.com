# Blocksy child-theme contract

The child theme is the only theme-owned PHP/CSS/JS extension surface. It must declare Blocksy as its parent, enqueue assets on the correct hooks, and remain functional when Blocksy Companion is disabled.

Use template overrides only when a public hook or Customizer setting cannot express the requirement. Keep overrides minimal and document the parent template version they were derived from. Do not copy `functions.php` from the parent theme.

Before release, render the affected template with anonymous and authenticated users, verify the mobile menu and keyboard focus order, and compare the parent-theme update diff for override conflicts.
