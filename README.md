# iq_ckeditor4_to_ckeditor5_migration
Utility module to help with the upgrade of Ckeditor4 to CKeditor5

# Prerequisites

Identify all CkeditorPlugin on your project.
- If from contrib modules: upgrade the module to a ckeditor5 compatible version or replacement
- If from custom modules: provide upgrade path if required by adding a CKEditor4To5Upgrade Plugin to your module
@see example
https://git.drupalcode.org/project/linkit/-/blob/7.x/src/Plugin/CKEditor4To5Upgrade/Linkit.php


# Usage

1. Add it to your project
```composer require-dev iqual/iq_ckeditor4_to_ckeditor5_migration```
2. Install the module
```drush en iq_ckeditor4_to_ckeditor5_migration```
3. Export the configutation
```drush cex -y```