# Experimental Flexbox

This fork implements horizontal, left-to-right Flexbox in native PHP. It is
opt-in and is not a claim of full browser or CSS3 conformance. Set the option
before loading the document:

```php
$options = new Dompdf\Options(['isFlexboxEnabled' => true]);
$dompdf = new Dompdf\Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->render();
```

With the option disabled (the default), `flex` and `inline-flex` retain their
previous block and inline-block fallbacks. CSS declarations can use the normal
syntax; no special HTML wrappers are required:

```html
<style>
.cards { display: flex; flex-wrap: wrap; gap: 12pt 16pt; }
.card { flex: 1 1 150pt; min-width: 0; padding: 10pt; box-sizing: border-box; }
</style>
<div class="cards"><div class="card">First</div><div class="card">Second</div></div>
```

## Implemented behavior

- `flex` and `inline-flex`; row/column directions and their reversed variants;
  nowrap, wrap and wrap-reverse; `flex-flow`; stable integer `order`.
- `flex-grow`, `flex-shrink`, `flex-basis` (`auto`, `content`, lengths,
  percentages and supported `calc()` expressions); the `flex` shorthand,
  including `none`, `auto` and its numeric forms.
- Iterative grow/shrink distribution, scaled shrink factors, factors below one,
  min/max freezing, automatic minimum sizes, margins, padding and `box-sizing`.
- `justify-content`: flex-start, flex-end, center, space-between, space-around,
  space-evenly. `align-items` and `align-self`: flex-start, flex-end, center,
  stretch, baseline (and auto for align-self). `align-content`: flex-start,
  flex-end, center, stretch and the three space-distribution values.
- Main and cross-axis auto margins; anonymous text items; blockified inline
  children; nested flex containers, images and tables as items; static alignment
  of absolutely positioned children without including them in flex distribution.
- `gap`, `row-gap`, `column-gap`, including percentages and supported `calc()`;
  no extra edge gaps. Cyclic percentages resolve to zero. Distributed alignment
  can add spacing beyond the specified gap.
- `visibility:collapse` uses a cross-size strut and removes main-axis space.
  This follows the Flexbox model rather than Chrome's hidden-like behavior.
- Breaks between visual rows/column items and inside text items, retaining solved
  widths and allowing siblings to continue independently. Forced item breaks
  apply to a whole row in row layout. Existing page-break avoidance is honored
  when the protected group can fit on a fresh page. Fixed/stretched box tails
  can continue without repeating completed text. Page boundaries suppress gaps.

## Architecture

`Layout/Flex/Calculator` is independent of PDF frames. It clones measurements
before resolving lines, sizes, auto margins, baselines and alignment.
`Layout/Flex/Measurer` reflows detached copies under a page that cannot fragment
or render. Its cache is local to one calculation and keyed by frame and size
constraints. Live generated content/counters are prepared in source order.

The Flex decorator/reflower normalize items, obtain measurements, invoke the
calculator, then commit positions. Assignments contain content-box dimensions
and resolved margins; CSS declarations are retained. Continuation records retain
visual groups and horizontal geometry, while unfinished content is remeasured.
Existing text/image/table reflowers and PDF renderers remain responsible for
rendering item contents. Tables retain the existing atomic-row limitation.

## Boundaries and compatibility

- RTL and vertical writing are outside this implementation. Modern sizing
  keywords such as `fit-content`, newer alignment keywords (`safe`, `unsafe`,
  first/last baseline, etc.), gap decorations and legacy prefixed Flexbox syntax
  are not added by this change. CSS Grid remains unsupported.
- Intrinsic sizing and baseline synthesis reuse Dompdf's existing metrics and
  approximations. This is not a full CSS Sizing or browser text-layout engine.
- Oversized replaced content and table rows retain Dompdf's existing limits.
  Flex inside table rows or absolutely/fixed-positioned boxes does not introduce
  new page boundaries of its own.
- Continuations preserve their original solved horizontal geometry. Documents
  whose page styles change the available width between pages require additional
  compatibility work before relying on this experimental layout.
- Independent PDF fragmentation is tested but does not promise identical page
  breaks to Chrome. Validate representative documents before enabling the option
  in a consuming application. The Laravel consumer is unchanged.
- Measurement copies cost more than ordinary block layout, particularly in
  deeply nested documents. No new production runtime dependency is required.

## Validation and maintenance

Run unit/layout tests and visual references with the project's dependencies:

```sh
php8.3 vendor/bin/phpunit tests/Layout/Flex tests/Css/FlexTest.php tests/LayoutTest/FlexTest.php
php8.3 vendor/bin/phpunit tests/OutputTest
php8.3 vendor/bin/phpunit
```

`tests/_files/flex/browser.html` is a font-independent geometry matrix. Its JSON
reference comes from Chrome, not from this implementation. The PHPUnit layout
check compares each box relative to its case container with a 0.03pt tolerance,
allowing browser subpixel rounding without accumulating offsets between cases.
To regenerate the independent reference (requires local Google Chrome):

```sh
python3 tests/scripts/flex-browser-reference.py
```

The matrix includes locally authored adaptations of the WPT
[small-factor](https://github.com/web-platform-tests/wpt/blob/master/css/css-flexbox/flex-factor-less-than-one.html)
and [invalid middle-basis shorthand](https://github.com/web-platform-tests/wpt/blob/master/css/css-flexbox/flex-shorthand-flex-basis-middle.html)
scenarios. This is not execution of the complete WPT suite.

The two PDF fixtures under `tests/_files/OutputTest/flex` enable Flexbox explicitly
in the existing output-test runner. Review rendered images before updating their
reference PDFs; output produced by the new engine is not an independent oracle.

Specification references:
[Flexbox](https://www.w3.org/TR/css-flexbox-1/),
[Box Alignment](https://www.w3.org/TR/css-align-3/),
[Gaps](https://www.w3.org/TR/css-gaps-1/).



Encontrei uma falha concreta na medição e algumas áreas que ainda não foram suficientemente testadas. Minha resposta anterior deveria ter sido mais específica: os testes que passaram não demonstram compatibilidade completa da medição de conteúdo com o navegador.

Falha reproduzida: largura intrínseca de inline-flex com flex-basis. Neste exemplo, os dois filhos têm base de 100pt:

<div style="display: inline-flex">
    <div style="flex: 0 0 100pt; height: 20pt"></div>
    <div style="flex: 0 0 100pt; height: 20pt"></div>
</div>

O Chrome mediu o contêiner em aproximadamente 200 pt; a implementação atual do Dompdf mediu 0 pt, embora tenha posicionado os filhos com 100 pt cada. Isso pode fazer o conteúdo invadir elementos próximos. O motivo está em Flex.php (src/FrameReflower/Flex.php:30): a largura intrínseca do contêiner é calculada a partir das larguras medidas dos filhos, sem considerar adequadamente o flex-basis deles. Uma alternativa temporária nesse exemplo é definir width: 200pt no contêiner.

As principais lacunas de validação que encontrei são:

- Texto e fontes: há testes com texto que quebra e continua em outras páginas, mas a comparação geométrica com o Chrome usa caixas sem texto. Ainda não comparamos sistematicamente palavras longas, fontes diferentes, tamanhos de fonte misturados e conteúdo com várias quebras possíveis.
- Alinhamento por baseline: existe implementação, mas não encontrei teste de layout específico comparando linhas de base de texto, imagens e caixas aninhadas. O medidor procura uma primeira linha de texto e usa uma aproximação quando não a encontra.
- Imagens: foi testada uma imagem SVG com largura definida e altura proporcional. Faltam combinações como dimensões automáticas, limites min/max, imagens grandes e imagens dentro de contêineres flex aninhados.
- Tabelas e flex aninhado: há testes básicos dos dois, mas não uma matriz de combinações com largura automática, percentuais, conteúdo longo e limites de tamanho. Essas medições dependem também dos algoritmos existentes de tabela e de blocos do Dompdf.
- Tamanhos intrínsecos e percentuais combinados: faltam casos com dimensões automáticas ou indefinidas, flex-basis: content, percentuais e min-width/max-width interagindo. Além disso, valores modernos como fit-content não foram adicionados nesta implementação.

Essas lacunas existem porque o calculador flex foi integrado aos mecanismos de medição que o Dompdf já tinha; ele não recebeu uma implementação completa de dimensionamento intrínseco de navegador. A matriz independente priorizou a matemática de distribuição, alinhamento e gap em caixas com dimensões controladas. A suíte completa passou, mas ela não contém todos esses cenários.

O caso do inline-flex acima é um defeito confirmado. Os demais itens são lacunas de cobertura ou limites conhecidos da abordagem; não estou afirmando que todos falham. Não alterei o código nesta análise.