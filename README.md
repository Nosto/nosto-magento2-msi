# Nosto Magento 2 - MSI support
This extension adds support for multi-source inventory for [Nosto extension](https://github.com/Nosto/nosto-magento2)

The module overrides [base module's](https://github.com/Nosto/nosto-magento2) stock provider with MSI compatible stock provider.

## Installing

Require the extension with composer:
```bash
composer require --no-update nosto/module-nosto-msi && composer update --no-dev
```

Enable the extension with:
```bash
bin/magento module:enable Nosto_Msi
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:clean
```
## License

Open Software License ("OSL") v3.0