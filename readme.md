# Módulo de Carrier para SigeWeb

Crie cotações de entrega diretamente na API do SigepWeb (Correios), com tabela de contigência para casos de queda da API, além disso tenha acompanhamento com tracking page e email ao cliente para acompanhamento de entregas.

## Badges
[![Magento - Coding Quality](https://github.com/elisei/sigep-web-carrier/actions/workflows/magento-coding-quality.yml/badge.svg)](https://github.com/elisei/sigep-web-carrier/actions/workflows/magento-coding-quality.yml)
[![Magento - Mess Detector](https://github.com/elisei/sigep-web-carrier/actions/workflows/mess-detector.yml/badge.svg)](https://github.com/elisei/sigep-web-carrier/actions/workflows/mess-detector.yml)
[![Magento - Php Stan](https://github.com/elisei/sigep-web-carrier/actions/workflows/phpstan.yml/badge.svg)](https://github.com/elisei/sigep-web-carrier/actions/workflows/phpstan.yml)

## Recursos

- Cotação de envio
- Tabela de contigência dinâmica
- Email de atualização de entrega
- Página de acompanhamento de entrega

## Instalação

```ssh
composer require o2ti/sigep-web-carrier
```

Após a instalação pelo Composer, execute os seguintes comandos:

```sh
bin/magento setup:upgrade
bin/magento setup:di:compile
```

