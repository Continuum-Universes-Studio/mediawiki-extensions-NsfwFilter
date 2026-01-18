# NSFWFilter MediaWiki Extension

Blurs images marked as NSFW in their file description, based on user preference.

- __Blurs images if file page description contains `__NSFW__`.  
- __Respects user preference set in Preferences → Appearance → Files (`displayfiltered`).__
- __Internationalized with translations for EN, ES, FR, DE, IT, KO, ZH, JA, PT, RU, UK.__

## Installation

1. Clone this repo into your MediaWiki `extensions/` directory.
2. Add to your `LocalSettings.php`:
   ```php
   wfLoadExtension( 'NsfwFilter' );
