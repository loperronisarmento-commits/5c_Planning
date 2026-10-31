# Espelho do sp_fc_postos_carteira com as regras de 31/10 (script 23), com a carga de 31/10.
#   - alívio da Mass PPV: limite_padrao e limite_compartilhada × (1 + alivio_mass_ppv);
#   - faturamento do mês fechado anterior (setembro, agg_statements_classification, sem reverse):
#     abaixo de 16k → 3 páginas por rep; de 16k a 32k → 2 páginas por rep (só regra PADRAO; sem dado = normal);
#   - mesma agência do Infloww como 2ª preferência no agrupamento (depois da mesma modelo).
# Uso: python -I -X utf8 regras_receita_espelho.py            (regras novas)
#      python -I -X utf8 regras_receita_espelho.py antigo     (regras de antes: tem que dar 178 postos)
import math, sys
from decimal import Decimal as D, ROUND_HALF_UP
ANTIGO = len(sys.argv) > 1 and sys.argv[1] == 'antigo'
ALIVIO = D(0) if ANTIGO else D('0.005')
LP, COMP = D(35) * (1 + ALIVIO), D(28) * (1 + ALIVIO)
LS, TETO, S1, S2, MAXP, MAXD, AFMIN = D(20), D(40), D('0.10'), D('0.20'), 3, 2, 2
R3, R2 = 16000, 32000
r1 = lambda x: x.quantize(D('0.1'), rounding=ROUND_HALF_UP)
ceil = lambda x: int(math.ceil(x))

CARGA = """
U1C1|A|1|Midwest Emma Free 221s|midwestemma|ASSISTENTE_FIXO|38.8214
U1C1|A|2|Rocket Reyna VIP|rocketreyna|PADRAO|13.8929
U1C1|A|3|Natasha Nice VIP|natashanice|PADRAO|17.9286
U1C1|A|4|Courtney|courtney|PADRAO|9.8036
U1C1|A|5|Janet VIP|janet|PADRAO|6.8036
U1C1|A|6|Mario Adrion|marioadrion|PADRAO|5.3393
U1C1|A|7|Ms Red VIP|msred|PADRAO|6.9107
U1C1|A|8|Yuni Yung|yuniyung|PADRAO|6.8393
U1C1|A|9|Aja|aja|PADRAO|7.2321
U1C1|A|10|Janet Free|janet|PADRAO|12.6786
U1C1|A|11|Victorya 188m|victorya|PADRAO|7.5179
U1C1|A|12|Melody|melody|PADRAO|8.4643
U1C1|A|13|Mandi CAT|mandicat|PADRAO|4.7321
U1C1|A|14|Katie Flood|katieflood|PADRAO|5.5536
U1C1|A|15|Alexis Clark|alexisclark|PADRAO|6.6071
U1C1|A|16|Ms Red Free|msred|PADRAO|8.5893
U1C1|A|17|Kaylin|kaylin|PADRAO|7.5714
U1C1|A|18|Aja Free|aja|PADRAO|3.3750
U1C1|A|19|Rocket Reyna OFTV|rocketreyna|PADRAO|5.0536
U1C1|C|1|Midwest Emma Free 221s|midwestemma|ASSISTENTE_FIXO|31.6071
U1C1|C|2|Rocket Reyna VIP|rocketreyna|PADRAO|15.1250
U1C1|C|3|Natasha Nice VIP|natashanice|PADRAO|22.0357
U1C1|C|4|Courtney|courtney|PADRAO|17.8036
U1C1|C|5|Janet VIP|janet|PADRAO|8.9464
U1C1|C|6|Mario Adrion|marioadrion|PADRAO|7.0357
U1C1|C|7|Ms Red VIP|msred|PADRAO|6.5000
U1C1|C|8|Yuni Yung|yuniyung|PADRAO|8.5357
U1C1|C|9|Aja|aja|PADRAO|6.3036
U1C1|C|10|Janet Free|janet|PADRAO|17.0179
U1C1|C|11|Victorya 188m|victorya|PADRAO|7.6786
U1C1|C|12|Melody|melody|PADRAO|8.8571
U1C1|C|13|Mandi CAT|mandicat|PADRAO|10.0893
U1C1|C|14|Katie Flood|katieflood|PADRAO|5.8571
U1C1|C|15|Alexis Clark|alexisclark|PADRAO|11.7143
U1C1|C|16|Ms Red Free|msred|PADRAO|6.3750
U1C1|C|17|Kaylin|kaylin|PADRAO|7.0000
U1C1|C|18|Aja Free|aja|PADRAO|2.3929
U1C1|C|19|Rocket Reyna OFTV|rocketreyna|PADRAO|12.7143
U1C1|D|1|Midwest Emma Free 221s|midwestemma|ASSISTENTE_FIXO|30.3571
U1C1|D|2|Rocket Reyna VIP|rocketreyna|PADRAO|38.7143
U1C1|D|3|Natasha Nice VIP|natashanice|PADRAO|14.6250
U1C1|D|4|Courtney|courtney|PADRAO|15.6429
U1C1|D|5|Janet VIP|janet|PADRAO|8.9643
U1C1|D|6|Mario Adrion|marioadrion|PADRAO|18.9821
U1C1|D|7|Ms Red VIP|msred|PADRAO|9.3929
U1C1|D|8|Yuni Yung|yuniyung|PADRAO|5.8929
U1C1|D|9|Aja|aja|PADRAO|5.8929
U1C1|D|10|Janet Free|janet|PADRAO|17.1250
U1C1|D|11|Victorya 188m|victorya|PADRAO|8.8571
U1C1|D|12|Melody|melody|PADRAO|7.2143
U1C1|D|13|Mandi CAT|mandicat|PADRAO|3.8214
U1C1|D|14|Katie Flood|katieflood|PADRAO|3.5536
U1C1|D|15|Alexis Clark|alexisclark|PADRAO|9.2500
U1C1|D|16|Ms Red Free|msred|PADRAO|9.3393
U1C1|D|17|Kaylin|kaylin|PADRAO|5.0536
U1C1|D|18|Aja Free|aja|PADRAO|2.7500
U1C1|D|19|Rocket Reyna OFTV|rocketreyna|PADRAO|7.8214
U1C2|A|1|Mikayla Free|mikayla|ASSISTENTE_FIXO|41.2679
U1C2|A|2|Mikayla Paid|mikayla|EXCLUSIVA|11.5179
U1C2|A|3|Sisi Rose|sisirose|PADRAO|37.0536
U1C2|A|4|Rebecca|rebecca|PADRAO|27.4643
U1C2|A|5|Molly Stewart VIP|mollystewart|PADRAO|23.2143
U1C2|A|6|Ariana Marie|arianamarie|PADRAO|21.1250
U1C2|A|7|Mo Saffari|mosaffari|PADRAO|18.5536
U1C2|A|8|Tiff 176m|tiff|PADRAO|11.3393
U1C2|A|9|Matty Caron|mattycaron|PADRAO|9.5357
U1C2|A|10|Livinia Roberts OFTV|liviniaroberts|PADRAO|13.8750
U1C2|A|11|Angel|angel|PADRAO|14.2143
U1C2|A|12|Eden The Doll|edenthe|PADRAO|5.3929
U1C2|A|13|Taylor Ballard|taylorballard|PADRAO|4.6429
U1C2|A|999|zOFTV Molly Stewart|mollystewart|PADRAO|25.4200
U1C2|C|1|Mikayla Free|mikayla|ASSISTENTE_FIXO|39.3571
U1C2|C|2|Mikayla Paid|mikayla|EXCLUSIVA|18.4107
U1C2|C|3|Sisi Rose|sisirose|PADRAO|41.2857
U1C2|C|4|Rebecca|rebecca|PADRAO|34.2679
U1C2|C|5|Molly Stewart VIP|mollystewart|PADRAO|21.4286
U1C2|C|6|Ariana Marie|arianamarie|PADRAO|16.1250
U1C2|C|7|Mo Saffari|mosaffari|PADRAO|19.3929
U1C2|C|8|Tiff 176m|tiff|PADRAO|16.1607
U1C2|C|9|Matty Caron|mattycaron|PADRAO|7.0714
U1C2|C|10|Livinia Roberts OFTV|liviniaroberts|PADRAO|16.6786
U1C2|C|11|Angel|angel|PADRAO|14.3393
U1C2|C|12|Eden The Doll|edenthe|PADRAO|5.8929
U1C2|C|13|Taylor Ballard|taylorballard|PADRAO|8.5536
U1C2|C|999|zOFTV Molly Stewart|mollystewart|PADRAO|25.4200
U1C2|D|1|Mikayla Free|mikayla|ASSISTENTE_FIXO|34.0536
U1C2|D|2|Mikayla Paid|mikayla|EXCLUSIVA|15.3750
U1C2|D|3|Sisi Rose|sisirose|PADRAO|34.3750
U1C2|D|4|Rebecca|rebecca|PADRAO|32.2857
U1C2|D|5|Molly Stewart VIP|mollystewart|PADRAO|22.0179
U1C2|D|6|Ariana Marie|arianamarie|PADRAO|13.7143
U1C2|D|7|Mo Saffari|mosaffari|PADRAO|19.6607
U1C2|D|8|Tiff 176m|tiff|PADRAO|13.4643
U1C2|D|9|Matty Caron|mattycaron|PADRAO|7.6250
U1C2|D|10|Livinia Roberts OFTV|liviniaroberts|PADRAO|8.1250
U1C2|D|11|Angel|angel|PADRAO|14.9286
U1C2|D|12|Eden The Doll|edenthe|PADRAO|6.7143
U1C2|D|13|Taylor Ballard|taylorballard|PADRAO|5.5536
U1C2|D|999|zOFTV Molly Stewart|mollystewart|PADRAO|25.4200
U2C1|A|1|Elly Clutch 100m|ellyclutch|PADRAO|44.6429
U2C1|A|2|Liv|liv|PADRAO|23.3393
U2C1|A|3|Madison Moore VIP 115m|madisonmoore|PADRAO|28.9643
U2C1|A|4|CJ VIP|cjmiles|PADRAO|18.8571
U2C1|A|5|CJ Miles|cjmiles|PADRAO|32.0000
U2C1|A|7|Apex VIP|apex|PADRAO|5.2857
U2C1|A|8|Thalia 147m|thalia|PADRAO|9.0714
U2C1|A|9|Levi Paid|levi|PADRAO|4.6429
U2C1|A|10|Ellie D|ellied|PADRAO|6.8929
U2C1|A|11|Levi Free|levi|PADRAO|10.1071
U2C1|A|13|Apex Free|apex|PADRAO|4.4821
U2C1|A|15|Blair VIP 155m|blair|PADRAO|14.6250
U2C1|A|999|Thalia 147t|thalia|PADRAO|25.4200
U2C1|C|1|Elly Clutch 100m|ellyclutch|PADRAO|37.4821
U2C1|C|2|Liv|liv|PADRAO|36.8393
U2C1|C|3|Madison Moore VIP 115m|madisonmoore|PADRAO|30.7143
U2C1|C|4|CJ VIP|cjmiles|PADRAO|18.6786
U2C1|C|5|CJ Miles|cjmiles|PADRAO|36.8929
U2C1|C|7|Apex VIP|apex|PADRAO|6.6071
U2C1|C|8|Thalia 147m|thalia|PADRAO|12.6964
U2C1|C|9|Levi Paid|levi|PADRAO|5.8393
U2C1|C|10|Ellie D|ellied|PADRAO|8.8929
U2C1|C|11|Levi Free|levi|PADRAO|7.2857
U2C1|C|13|Apex Free|apex|PADRAO|4.6786
U2C1|C|15|Blair VIP 155m|blair|PADRAO|21.0357
U2C1|C|999|Thalia 147t|thalia|PADRAO|25.4200
U2C1|D|1|Elly Clutch 100m|ellyclutch|PADRAO|44.6429
U2C1|D|2|Liv|liv|PADRAO|19.8750
U2C1|D|3|Madison Moore VIP 115m|madisonmoore|PADRAO|41.4464
U2C1|D|4|CJ VIP|cjmiles|PADRAO|13.9464
U2C1|D|5|CJ Miles|cjmiles|PADRAO|31.1071
U2C1|D|7|Apex VIP|apex|PADRAO|6.4107
U2C1|D|8|Thalia 147m|thalia|PADRAO|11.7679
U2C1|D|9|Levi Paid|levi|PADRAO|4.7857
U2C1|D|10|Ellie D|ellied|PADRAO|9.2857
U2C1|D|11|Levi Free|levi|PADRAO|7.6071
U2C1|D|13|Apex Free|apex|PADRAO|4.1964
U2C1|D|15|Blair VIP 155m|blair|PADRAO|20.8929
U2C1|D|999|Thalia 147t|thalia|PADRAO|25.4200
U2C2|A|1|Nicole Doshi|nicoledoshi|PADRAO|30.4286
U2C2|A|2|Brandi Love|brandilove|PADRAO|24.3750
U2C2|A|3|Holly Jane|hollyjane|PADRAO|15.0893
U2C2|A|4|Alix Lynx|alixlynx|PADRAO|17.2143
U2C2|A|5|Brandi Love FREE|brandilove|PADRAO|10.0179
U2C2|A|6|Sean Adams|seanadams|PADRAO|9.2500
U2C2|A|7|Sarah H 329m|sarahh|PADRAO|9.7143
U2C2|A|8|Lexis Star|lexisstar|PADRAO|11.6607
U2C2|A|9|Jay|jay|PADRAO|7.1071
U2C2|A|10|Charlie 306m|charlie|PADRAO|6.3750
U2C2|A|11|Sumner|sumner|PADRAO|4.9821
U2C2|A|12|Abg|abg|PADRAO|4.9464
U2C2|A|14|Ivy Vale|ivyvale|PADRAO|21.1400
U2C2|A|15|Aline Novak|alinenovak|PADRAO|12.4300
U2C2|A|16|Keera|keera|PADRAO|2.1429
U2C2|A|999|Nicole OFTV|nicoledoshi|PADRAO|25.4200
U2C2|C|1|Nicole Doshi|nicoledoshi|PADRAO|33.1607
U2C2|C|2|Brandi Love|brandilove|PADRAO|23.1250
U2C2|C|3|Holly Jane|hollyjane|PADRAO|16.5536
U2C2|C|4|Alix Lynx|alixlynx|PADRAO|17.9107
U2C2|C|5|Brandi Love FREE|brandilove|PADRAO|17.4464
U2C2|C|6|Sean Adams|seanadams|PADRAO|9.3571
U2C2|C|7|Sarah H 329m|sarahh|PADRAO|7.4107
U2C2|C|8|Lexis Star|lexisstar|PADRAO|11.5357
U2C2|C|9|Jay|jay|PADRAO|7.9643
U2C2|C|10|Charlie 306m|charlie|PADRAO|4.6071
U2C2|C|11|Sumner|sumner|PADRAO|8.6250
U2C2|C|12|Abg|abg|PADRAO|9.5000
U2C2|C|14|Ivy Vale|ivyvale|PADRAO|21.1400
U2C2|C|15|Aline Novak|alinenovak|PADRAO|12.4300
U2C2|C|16|Keera|keera|PADRAO|3.3036
U2C2|C|999|Nicole OFTV|nicoledoshi|PADRAO|25.4200
U2C2|D|1|Nicole Doshi|nicoledoshi|PADRAO|39.3214
U2C2|D|2|Brandi Love|brandilove|PADRAO|24.5536
U2C2|D|3|Holly Jane|hollyjane|PADRAO|16.1964
U2C2|D|4|Alix Lynx|alixlynx|PADRAO|14.6786
U2C2|D|5|Brandi Love FREE|brandilove|PADRAO|16.8393
U2C2|D|6|Sean Adams|seanadams|PADRAO|7.2321
U2C2|D|7|Sarah H 329m|sarahh|PADRAO|6.8393
U2C2|D|8|Lexis Star|lexisstar|PADRAO|12.0714
U2C2|D|9|Jay|jay|PADRAO|5.6607
U2C2|D|10|Charlie 306m|charlie|PADRAO|6.0179
U2C2|D|11|Sumner|sumner|PADRAO|5.3393
U2C2|D|12|Abg|abg|PADRAO|4.5536
U2C2|D|14|Ivy Vale|ivyvale|PADRAO|21.1400
U2C2|D|15|Aline Novak|alinenovak|PADRAO|12.4300
U2C2|D|16|Keera|keera|PADRAO|2.8929
U2C2|D|999|Nicole OFTV|nicoledoshi|PADRAO|25.4200
U3C1|A|2|Lily Lou OFTV|lilylou|PADRAO|17.1429
U3C1|A|3|Goddess Sylvanas 150m|goddesssylvanas|PADRAO|12.8214
U3C1|A|4|Mrs Robinson 303m|mrsrobinson|PADRAO|4.5536
U3C1|A|5|Sebi Barraza|sebibarraza|PADRAO|5.0179
U3C1|A|6|Bianca|bianca|SLA80|6.2500
U3C1|A|7|Maya|maya|PADRAO|5.7857
U3C1|A|8|Tiny|tiny|PADRAO|6.2321
U3C1|A|999|Aria|aria|PADRAO|1.0000
U3C1|A|999|Becky Recme|beckyrecme|PADRAO|11.3214
U3C1|A|999|Haven  Free|haven|PADRAO|14.1900
U3C1|A|999|Jessie|jessie|PADRAO|3.3214
U3C1|A|999|Kas|kas|PADRAO|0.7321
U3C1|A|999|Liam|liam|PADRAO|3.9286
U3C1|A|999|Silvia Saige VIP|silviasaige|PADRAO|1.5714
U3C1|A|999|Sophie Sierra|sophiesierra|PADRAO|1.6429
U3C1|C|2|Lily Lou OFTV|lilylou|PADRAO|23.4286
U3C1|C|3|Goddess Sylvanas 150m|goddesssylvanas|PADRAO|14.1964
U3C1|C|4|Mrs Robinson 303m|mrsrobinson|PADRAO|4.3750
U3C1|C|5|Sebi Barraza|sebibarraza|PADRAO|9.5714
U3C1|C|6|Bianca|bianca|SLA80|5.3036
U3C1|C|7|Maya|maya|PADRAO|7.3929
U3C1|C|8|Tiny|tiny|PADRAO|8.8214
U3C1|C|999|Aria|aria|PADRAO|2.6964
U3C1|C|999|Becky Recme|beckyrecme|PADRAO|14.0357
U3C1|C|999|Haven  Free|haven|PADRAO|14.1900
U3C1|C|999|Jessie|jessie|PADRAO|6.7143
U3C1|C|999|Kas|kas|PADRAO|1.0536
U3C1|C|999|Liam|liam|PADRAO|4.5893
U3C1|C|999|Silvia Saige VIP|silviasaige|PADRAO|2.0179
U3C1|C|999|Sophie Sierra|sophiesierra|PADRAO|1.9286
U3C1|D|2|Lily Lou OFTV|lilylou|PADRAO|13.5714
U3C1|D|3|Goddess Sylvanas 150m|goddesssylvanas|PADRAO|8.8929
U3C1|D|4|Mrs Robinson 303m|mrsrobinson|PADRAO|7.8214
U3C1|D|5|Sebi Barraza|sebibarraza|PADRAO|4.6429
U3C1|D|6|Bianca|bianca|SLA80|4.7321
U3C1|D|7|Maya|maya|PADRAO|6.8214
U3C1|D|8|Tiny|tiny|PADRAO|6.0893
U3C1|D|999|Aria|aria|PADRAO|2.3036
U3C1|D|999|Becky Recme|beckyrecme|PADRAO|14.5179
U3C1|D|999|Haven  Free|haven|PADRAO|14.1900
U3C1|D|999|Jessie|jessie|PADRAO|6.0000
U3C1|D|999|Kas|kas|PADRAO|1.5000
U3C1|D|999|Liam|liam|PADRAO|4.9107
U3C1|D|999|Silvia Saige Free|silviasaige|PADRAO|1.1071
U3C1|D|999|Silvia Saige VIP|silviasaige|PADRAO|2.9643
U3C1|D|999|Sophie Sierra|sophiesierra|PADRAO|2.1250
U3C2|A|1|Cynthia|cynthia|PADRAO|31.9107
U3C2|A|2|Britt VIP|britt|PADRAO|23.1429
U3C2|A|3|Diana Rider|dianarider|PADRAO|25.5357
U3C2|A|4|Krystal 141m|krystal|PADRAO|15.7321
U3C2|A|5|Isla|isla|PADRAO|14.1607
U3C2|A|6|Draya|draya|PADRAO|13.2321
U3C2|A|7|Coco Bunnie 260m|cocobunnie|PADRAO|7.5357
U3C2|A|8|Blake|blake|PADRAO|6.8929
U3C2|A|9|Marie Dee VIP|mariedee|PADRAO|13.0179
U3C2|A|10|Jena Wolfy VIP 001m|jenawolfy|PADRAO|7.6607
U3C2|A|12|Mia Melons|miamelons|PADRAO|5.4464
U3C2|A|13|Jena Wolfy Free 001s|jenawolfy|PADRAO|1.5536
U3C2|A|999|Maddie|maddiecavill|PADRAO|2.7857
U3C2|A|999|zOFTV Diana Rider|dianarider|PADRAO|25.4200
U3C2|C|1|Cynthia|cynthia|PADRAO|46.8929
U3C2|C|2|Britt VIP|britt|PADRAO|27.3929
U3C2|C|3|Diana Rider|dianarider|PADRAO|25.4821
U3C2|C|4|Krystal 141m|krystal|PADRAO|18.2321
U3C2|C|5|Isla|isla|PADRAO|14.9464
U3C2|C|6|Draya|draya|PADRAO|14.9107
U3C2|C|7|Coco Bunnie 260m|cocobunnie|PADRAO|7.6429
U3C2|C|8|Blake|blake|PADRAO|7.6607
U3C2|C|9|Marie Dee VIP|mariedee|PADRAO|24.8393
U3C2|C|10|Jena Wolfy VIP 001m|jenawolfy|PADRAO|10.6429
U3C2|C|12|Mia Melons|miamelons|PADRAO|7.1964
U3C2|C|13|Jena Wolfy Free 001s|jenawolfy|PADRAO|1.8214
U3C2|C|14|Krystal 141t|krystal|PADRAO|0.7321
U3C2|C|999|Maddie|maddiecavill|PADRAO|3.4643
U3C2|C|999|zOFTV Diana Rider|dianarider|PADRAO|25.4200
U3C2|D|1|Cynthia|cynthia|PADRAO|39.3929
U3C2|D|2|Britt VIP|britt|PADRAO|23.0000
U3C2|D|3|Diana Rider|dianarider|PADRAO|22.3393
U3C2|D|4|Krystal 141m|krystal|PADRAO|14.7143
U3C2|D|5|Isla|isla|PADRAO|14.1429
U3C2|D|6|Draya|draya|PADRAO|11.8750
U3C2|D|7|Coco Bunnie 260m|cocobunnie|PADRAO|9.8571
U3C2|D|8|Blake|blake|PADRAO|5.6786
U3C2|D|9|Marie Dee VIP|mariedee|PADRAO|21.5714
U3C2|D|10|Jena Wolfy VIP 001m|jenawolfy|PADRAO|8.3214
U3C2|D|12|Mia Melons|miamelons|PADRAO|5.5000
U3C2|D|13|Jena Wolfy Free 001s|jenawolfy|PADRAO|1.8750
U3C2|D|999|Maddie|maddiecavill|PADRAO|3.2679
U3C2|D|999|zOFTV Diana Rider|dianarider|PADRAO|25.4200
"""
# faturamento bruto de setembro (agg_statements_classification, sem reverse); ausente = sem dado
FAT = {'Jena Wolfy OFTV 001t': 14, 'Britt OFTV': 22, 'MandiCat OFTV': 38, 'Jena Wolfy Free 001s': 53, 'Aline Novak': 60,
 'Blair OFTV 155t': 66, 'zOFTV Diana Rider': 93, 'zOFTV Molly Stewart': 127, 'Krystal 141t': 688, 'Rebecca OFTV': 792,
 'Apex Free': 2784, 'Madison Moore Free 115s': 2866, 'Aja Free': 6736, 'Sumner': 7024, 'Mia Melons': 8680,
 'Rocket Reyna OFTV': 8980, 'Charlie 306m': 11506, 'Abg': 15408, 'Jena Wolfy VIP 001m': 15744, 'Maya': 15820,
 'Taylor Ballard': 16626, 'Kaylin': 16760, 'Bianca': 17530, 'Apex VIP': 17980, 'Coco Bunnie 260m': 18071,
 'Mandi CAT': 18710, 'Kas': 18717, 'Tiny': 19760, 'Sarah H 329m': 19809, 'Keera': 20188, 'Mrs Robinson 303m': 20873,
 'Ellie D': 22059, 'Aja': 22303, 'Alexis Clark': 22447, 'Victorya 188m': 22573, 'Liam': 23480, 'Katie Flood': 23582,
 'Melody': 24175, 'Draya': 26153, 'Maddie': 26209, 'Yuni Yung': 26837, 'Eden The Doll': 27560, 'Blake': 28262,
 'Levi Paid': 28581, 'Ms Red Free': 28680, 'Aria': 28913, 'Janet Free': 29775, 'Sebi Barraza': 30388, 'Mo Saffari': 31498,
 'Jessie': 33144, 'Isla': 33421, 'Levi Free': 33557, 'Mario Adrion': 34863, 'Sean Adams': 36895, 'Angel': 38026,
 'Thalia 147m': 38868, 'Jay': 40909, 'Silvia Saige Free': 41791, 'Sophie Sierra': 44867, 'Matty Caron': 46994,
 'Courtney': 48297, 'Janet VIP': 53526, 'Becky Recme': 56109, 'Goddess Sylvanas 150m': 56443, 'Ms Red VIP': 58139,
 'Krystal 141m': 61163, 'Ariana Marie': 62328, 'CJ Miles': 64917, 'Brandi Love FREE': 68764, 'Tiff 176m': 69104,
 'Diana Rider': 69796, 'Blair VIP 155m': 72864, 'Alix Lynx': 73056, 'Rocket Reyna VIP': 75933, 'Ivy Vale': 91447,
 'Natasha Nice VIP': 99939, 'Rebecca': 103280, 'Holly Jane': 111055, 'Molly Stewart VIP': 115634, 'CJ VIP': 123814,
 'Silvia Saige VIP': 130539, 'Marie Dee VIP': 134122, 'Liv': 159923, 'Brandi Love': 171963, 'Sisi Rose': 174816,
 'Midwest Emma Free 221s': 178360, 'Britt VIP': 183519, 'Madison Moore VIP 115m': 189018, 'Nicole Doshi': 191575,
 'Mikayla Paid': 204313, 'Cynthia': 250634, 'Elly Clutch 100m': 262811, 'Mikayla Free': 478251}
# agência do Infloww pelo nome (só nomes com uma agência); ausente = sem agência
AG = {'Nicole OFTV': 1, 'Thalia 147t': 2, 'Lily Lou OFTV': 1, 'Livinia Roberts OFTV': 1, 'Jena Wolfy OFTV 001t': 2,
 'MandiCat OFTV': 1, 'Jena Wolfy Free 001s': 2, 'Blair OFTV 155t': 2, 'Krystal 141t': 2, 'Rebecca OFTV': 1, 'Apex Free': 1,
 'Madison Moore Free 115s': 2, 'Aja Free': 1, 'Sumner': 3, 'Mia Melons': 1, 'Rocket Reyna OFTV': 1, 'Charlie 306m': 2,
 'Abg': 1, 'Jena Wolfy VIP 001m': 2, 'Maya': 1, 'Kaylin': 1, 'Bianca': 3, 'Apex VIP': 1, 'Coco Bunnie 260m': 2,
 'Mandi CAT': 1, 'Kas': 1, 'Tiny': 1, 'Sarah H 329m': 2, 'Keera': 1, 'Mrs Robinson 303m': 2, 'Ellie D': 3, 'Aja': 1,
 'Alexis Clark': 3, 'Victorya 188m': 2, 'Liam': 3, 'Katie Flood': 3, 'Melody': 1, 'Draya': 1, 'Maddie': 3,
 'Eden The Doll': 3, 'Blake': 3, 'Levi Paid': 3, 'Aria': 1, 'Janet Free': 1, 'Sebi Barraza': 3, 'Mo Saffari': 3,
 'Jessie': 1, 'Isla': 1, 'Levi Free': 3, 'Mario Adrion': 3, 'Sean Adams': 3, 'Angel': 1, 'Thalia 147m': 2, 'Jay': 3,
 'Sophie Sierra': 1, 'Matty Caron': 3, 'Courtney': 1, 'Janet VIP': 1, 'Goddess Sylvanas 150m': 2, 'Krystal 141m': 2,
 'CJ Miles': 1, 'Tiff 176m': 2, 'Blair VIP 155m': 2, 'Rocket Reyna VIP': 1, 'Rebecca': 1, 'CJ VIP': 1, 'Sisi Rose': 1,
 'Midwest Emma Free 221s': 2, 'Madison Moore VIP 115m': 2, 'Nicole Doshi': 1, 'Mikayla Paid': 3, 'Elly Clutch 100m': 2,
 'Mikayla Free': 3}

def classe(p, regra):
    if ANTIGO or regra != 'PADRAO' or p not in FAT: return 0
    return 3 if FAT[p] < R3 else 2 if FAT[p] < R2 else 0

class Bin:
    def __init__(s, i, forc=0): s.i, s.it, s.forc = i, [], forc
    n = property(lambda s: len(s.it))
    soma = property(lambda s: sum(x['f'] for x in s.it))
    apoio = property(lambda s: max([x['a'] for x in s.it] or [0]))
    mods = property(lambda s: {x['m'] for x in s.it})
    unico = property(lambda s: next(iter(s.mods)) if len(s.mods) == 1 else None)
    def tem_m(s, m): return m in s.mods
    def tem_ag(s, ag): return ag is not None and any(x['ag'] == ag for x in s.it)

def postos(rows):
    out = []; itens = []; forc = []
    for (o, p, m, rg, F) in sorted(rows, key=lambda r: (r[0], r[1])):
        F = max(F, D(1)); k = 0; c = classe(p, rg)
        if rg == 'SLA80': k = max(1, ceil(F / LS))
        elif rg == 'ASSISTENTE_FIXO': k = max(AFMIN, ceil(F / LP))
        if k: out += [('titular', 1, 0)] + [('assistente', 1, 0)] * (k - 1); continue
        if c: forc.append(dict(p=p, m=m, ag=AG.get(p), f=r1(F), a=0, c=c)); continue
        if F > TETO:
            O = F - LP; out.append(('titular', 1, 0))
            if O > COMP: out += [('assistente', 1, 0)] * ceil(O / LP)
            else: itens.append(dict(p=p, m=m, ag=AG.get(p), f=r1(O), a=1))
        elif F > COMP or rg == 'EXCLUSIVA': out.append(('titular', 1, 0))
        else: itens.append(dict(p=p, m=m, ag=AG.get(p), f=r1(F), a=0))
    nb = 0; bins = []
    # Fase F: regra de faturamento
    f3 = sorted([x for x in forc if x['c'] == 3], key=lambda x: (-x['f'], x['p']))
    f2 = sorted([x for x in forc if x['c'] == 2], key=lambda x: (-x['f'], x['p']))
    pref = lambda b, x: (-b.tem_m(x['m']), -b.tem_ag(x['ag']), b.soma, b.i)
    b3 = []
    for _ in range(ceil(len(f3) / 3)): nb += 1; b3.append(Bin(nb, 1))
    for x in f3: min((b for b in b3 if b.n < 3), key=lambda b: pref(b, x)).it.append(x)
    while f2 and any(b.n < 3 for b in b3):      # vagas dos grupos de 3 com as páginas de 16k a 32k
        b = min((b for b in b3 if b.n < 3), key=lambda b: (b.soma, b.i))
        x = min(f2, key=lambda x: (-b.tem_m(x['m']), -b.tem_ag(x['ag']), x['f'], x['p']))
        b.it.append(x); f2.remove(x)
    b2 = []
    for _ in range(ceil(len(f2) / 2)): nb += 1; b2.append(Bin(nb, 1))
    for x in f2: min((b for b in b2 if b.n < 2), key=lambda b: pref(b, x)).it.append(x)
    for b in [b for b in b3 + b2 if b.n == 1]:  # sozinha sem par forçado: volta para o agrupamento normal
        itens.append(b.it.pop())
    bins += [b for b in b3 + b2 if b.n]
    # Fase 2a: mesma modelo
    nfor = nb; nb = max([nb] + [0]); grupos = []
    for x in sorted(itens, key=lambda x: -x['f']):
        cand = [b for b in grupos if b.unico == x['m'] and b.n < MAXP and not (x['a'] and b.apoio)
                and b.soma + x['f'] <= LP * (1 - S1 * b.n)]
        if cand: min(cand, key=lambda b: (LP * (1 - S1 * b.n) - (b.soma + x['f']), b.i)).it.append(x)
        else: nb += 1; b = Bin(nb); b.it.append(x); grupos.append(b)
    solos = [b for b in grupos if b.n == 1]; grupos = [b for b in grupos if b.n > 1]
    nb1 = max([nb] + [b.i for b in solos]); nb = nb1
    novos = []
    for x in sorted([b.it[0] for b in solos], key=lambda x: -x['f']):
        def cabe(b):
            mesma = b.unico == x['m']
            return (b.n < (MAXP if mesma else MAXD) and not (x['a'] and b.apoio)
                    and b.soma + x['f'] <= LP * (1 - (S1 if mesma else S2) * b.n))
        cand = [b for b in novos if cabe(b)]
        if cand:
            min(cand, key=lambda b: (-b.tem_m(x['m']), -(0 if ANTIGO else b.tem_ag(x['ag'])),
                                     LP * (1 - (S1 if b.unico == x['m'] else S2) * b.n) - (b.soma + x['f']), b.i)).it.append(x)
        else: nb += 1; b = Bin(nb); b.it.append(x); novos.append(b)
    bins += grupos + novos
    for b in bins:
        cap = LP * (1 - (S1 if b.unico else S2) * (b.n - 1))
        out.append(('double' if b.n > 1 else 'titular', b.n, b.forc, b.soma, cap, [x['p'] for x in b.it]))
    return out

SEM_ESCALA = ('zOFTV',)   # fc_pagina_sem_escala: começo do nome (zOFTV%); não ocupam posto
dados = {}
for l in CARGA.strip().split('\n'):
    c, t, o, p, m, rg, f = l.split('|')
    if not ANTIGO and p.startswith(SEM_ESCALA): continue
    dados.setdefault((c, t), []).append((int(o), p, m, rg, D(f)))
tot = 0; forc_tot = 0; excesso = []
por_un = {}
for (c, t), rows in sorted(dados.items()):
    ps = postos(rows); tot += len(ps)
    por_un[(c[:2], t)] = por_un.get((c[:2], t), 0) + len(ps)
    fz = [x for x in ps if len(x) > 3 and x[2]]
    forc_tot += len(fz)
    for x in fz:
        if x[3] > x[4]: excesso.append((c, t, float(x[3]), float(round(x[4], 1)), x[5]))
    print(c, t, len(ps), 'postos', ' | forçados:', '; '.join('%s (%.1f)' % ('+'.join(x[5]), x[3]) for x in fz))
tit = sum(math.ceil(n * 1.5) for n in por_un.values())
print('TOTAL', tot, 'postos ·', forc_tot, 'forçados · titulares', tit)
print('por unidade e turno:', ' '.join('%s%s %d' % (u, t, n) for (u, t), n in sorted(por_un.items())))
print('forçados acima da capacidade:', len(excesso))
for e in excesso: print('  ', e)
