---
version: alpha
name: GSE
description: Interface de trabalho da secretaria da E.E. São José
colors:
  primary: '#16508f'
  primary-dark: '#0c3566'
  background: '#eef4fb'
  border: '#d5e1ee'
  text: '#152b46'
  muted: '#586b82'
---

# Identidade do GSE

O GSE atende a secretaria escolar em português do Brasil. A navegação usa o brasão da escola, o menu azul e painéis claros. `public/assets/css/institucional.css` é a fonte das cores compartilhadas, dos botões e dos tratamentos de foco. As telas usam o layout em `src/Views/layouts/app.php`.

Na Agenda, a grade mensal dá visão rápida dos dias e a lista torna os compromissos legíveis em telas pequenas. Avisos e feriados usam rótulos visíveis junto à cor. Datas respeitam o fuso da aplicação e são exibidas em `DD/MM/AAAA` onde o ano é necessário.

Em Etiquetas, os controles pertencem à interface institucional e a folha mantém as dimensões de impressão do SCR. A prévia mostra os campos digitados no navegador. A impressão prioriza contraste, limites da folha A4 e espaço para recorte.

Os formulários reutilizam campos nativos, proteção CSRF e mensagens do layout. A edição retorna ao período da agenda, a exclusão solicita confirmação e a importação exige revisão da prévia. Os padrões de navegação e recuperação estão descritos em `Documentação/USABILIDADE-2026-10-23.md`. As regras de acesso estão em `SECURITY-OPERATIONS.md`.
