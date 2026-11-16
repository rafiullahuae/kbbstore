"""KBB Studio · occupation library (Work Abroad, owner 16 Nov 2026: "i need work abroad also, for different occupations
... focus more on the images, specially the pictures of the workers, employees in specific uniform").

    python3 build-occupations.py   ->  occupations.json, icons/<tool>.svg, figures/<family>.svg (+ figures/sheet.html)

One source of truth for the reel app (app/stock: occupation quick-filters, library/people/<occupation>/), the Ad
Studio (docs/plans/ad-studio/build.py reads occupations.json and composes a placeholder figure per occupation with
figures.py) and the later local image model (the prompt template). Everything here is data or original drawing:
nothing is fetched, nothing is licensed from anyone.

Honesty: `countries` are SUGGESTIONS to start from (editable), never a statement that a country is recruiting now;
no salary, visa or contract value is stored anywhere -- the ads carry ‹edit› placeholders for those.
"""
import json, os
from icons import ICONS
from figures import figure

D = os.path.dirname(os.path.abspath(__file__))

# tool id -> (English, Arabic); the icon has the same id (icons.py)
TOOLS = {
    'wrench': ('Wrench', 'مفتاح ربط'), 'pipe-wrench': ('Pipe wrench', 'مفتاح أنابيب'), 'pipe': ('Pipes', 'أنابيب'),
    'plunger': ('Plunger', 'مكبس تسليك'), 'screwdriver': ('Screwdriver', 'مفك براغي'), 'pliers': ('Pliers', 'كمّاشة'),
    'multimeter': ('Multimeter', 'جهاز قياس'), 'bolt': ('Electrics', 'كهرباء'), 'cable': ('Cables & plugs', 'كابلات وقوابس'),
    'welding-mask': ('Welding helmet', 'خوذة لحام'), 'flame': ('Torch', 'شعلة لحام'), 'hammer': ('Hammer', 'مطرقة'),
    'saw': ('Saw', 'منشار'), 'drill': ('Drill', 'مثقاب'), 'tape-measure': ('Tape measure', 'شريط قياس'),
    'level': ('Spirit level', 'ميزان ماء'), 'trowel': ('Trowel', 'مسطرين'), 'brick': ('Bricks & blocks', 'طوب'),
    'paint-roller': ('Paint roller', 'رولة دهان'), 'brush': ('Brush', 'فرشاة'), 'snowflake': ('Cooling', 'تبريد'),
    'gauge': ('Pressure gauge', 'مقياس ضغط'), 'rebar': ('Rebar', 'حديد تسليح'), 'scaffold': ('Scaffolding', 'سقالات'),
    'crane': ('Crane', 'رافعة'), 'forklift': ('Forklift', 'رافعة شوكية'), 'hard-hat': ('Hard hat', 'خوذة'),
    'vest': ('Hi-vis vest', 'سترة عاكسة'), 'gloves': ('Work gloves', 'قفازات عمل'), 'boot': ('Safety boots', 'حذاء سلامة'),
    'toolbox': ('Toolbox', 'صندوق عدة'), 'wheel': ('Steering wheel', 'مقود'), 'truck': ('Truck', 'شاحنة'),
    'car': ('Car', 'سيارة'), 'bus': ('Bus', 'حافلة'), 'scooter': ('Scooter', 'دراجة نارية'), 'parcel': ('Parcels', 'طرود'),
    'pallet': ('Pallets', 'منصات'), 'scanner': ('Barcode scanner', 'ماسح باركود'), 'route': ('Routes', 'مسارات'),
    'stethoscope': ('Stethoscope', 'سماعة طبية'), 'syringe': ('Injections', 'حقن'), 'heart-pulse': ('Patient care', 'رعاية المرضى'),
    'pill': ('Medicines', 'أدوية'), 'microscope': ('Microscope', 'مجهر'), 'test-tube': ('Samples', 'عينات'),
    'wheelchair': ('Mobility care', 'رعاية الحركة'), 'first-aid': ('First aid', 'إسعافات أولية'), 'baby': ('Child care', 'رعاية الأطفال'),
    'chef-hat': ('Kitchen', 'مطبخ'), 'knife': ('Knives', 'سكاكين'), 'pan': ('Pan', 'مقلاة'), 'whisk': ('Whisk', 'مضرب'),
    'coffee': ('Coffee', 'قهوة'), 'cloche': ('Service', 'تقديم'), 'tray': ('Tray', 'صينية'), 'bed': ('Rooms', 'غرف'),
    'key': ('Keys', 'مفاتيح'), 'bell': ('Front desk', 'الاستقبال'), 'bucket': ('Bucket & mop', 'دلو وممسحة'),
    'spray': ('Cleaning spray', 'بخاخ تنظيف'), 'broom': ('Broom', 'مكنسة'), 'shield': ('Safety & security', 'أمن وسلامة'),
    'radio': ('Radio', 'جهاز لاسلكي'), 'cctv': ('CCTV', 'كاميرات مراقبة'), 'tag': ('Prices & stock', 'أسعار ومخزون'),
    'register': ('Till', 'صندوق الدفع'), 'scissors': ('Scissors', 'مقص'), 'comb': ('Comb', 'مشط'), 'razor': ('Razor', 'شفرة حلاقة'),
    'makeup': ('Make-up', 'مكياج'), 'needle': ('Needle', 'إبرة'), 'spool': ('Thread', 'خيوط'), 'headset': ('Headset', 'سماعة رأس'),
    'book': ('Books', 'كتب'), 'board': ('Board', 'سبورة'), 'grad': ('Learning', 'تعليم'), 'abc': ('Early learning', 'تعليم مبكر'),
    'tractor': ('Tractor', 'جرار'), 'wheat': ('Crops', 'محاصيل'), 'apple': ('Fruit', 'فواكه'), 'basket': ('Harvest basket', 'سلة الحصاد'),
    'cleaver': ('Cleaver', 'ساطور'), 'bread': ('Bread', 'خبز'), 'fish': ('Fish', 'سمك'), 'sprout': ('Plants', 'نباتات'),
    'gear': ('Machinery', 'آلات'), 'factory': ('Factory', 'مصنع'), 'conveyor': ('Production line', 'خط إنتاج'),
    'tape-gun': ('Packing', 'تغليف'), 'oil-can': ('Oil & service', 'زيت وصيانة'), 'battery': ('Battery', 'بطارية'),
    'engine': ('Engine', 'محرك'), 'helmet-face': ('Face shield', 'واقي وجه'), 'laptop': ('Laptop', 'حاسوب'), 'code': ('Code', 'برمجة'),
    'calculator': ('Calculator', 'آلة حاسبة'), 'chart': ('Reports', 'تقارير'), 'briefcase': ('Office', 'مكتب'),
    'blueprint': ('Drawings', 'مخططات'), 'clipboard': ('Checklists', 'قوائم'), 'id-card': ('ID & access', 'بطاقة دخول'),
    'globe': ('Worldwide', 'حول العالم'), 'plane': ('Travel', 'سفر'), 'passport': ('Passport', 'جواز سفر'), 'users': ('Team', 'فريق'),
}
assert set(TOOLS) <= set(ICONS), set(TOOLS) - set(ICONS)

# figure families: the default uniform parts (figures.py); an occupation overrides any part
FAMILIES = {
    'trades': ('Trades & construction', dict(top='vest', hat='hardhat', chest=['toolbelt'], gloves='dark')),
    'driver': ('Drivers & transport', dict(top='polo', hat='cap', arms='wheel')),
    'care': ('Nursing & care', dict(top='scrubs', hat='none', hair='bun', chest=['stethoscope'])),
    'hospitality': ('Chefs & hospitality', dict(top='chef', hat='toque')),
    'teacher': ('Teachers & education', dict(top='cardigan', hat='none', hair='short', arms='book')),
    'security': ('Security', dict(top='security', hat='peaked', chest=['badge', 'radio'])),
    'factory': ('Factory & production', dict(top='coverall', hat='earmuffs', glasses=True, gloves='blue')),
    'farm': ('Farm & food', dict(top='bib', hat='wide', sleeves='rolled', gloves='tan')),
    'office': ('Office & IT', dict(top='blazer', hat='none', chest=['lanyard'])),
    'retail': ('Retail & services', dict(top='polo', hat='none', chest=['lanyard'])),
}

SECTORS = {
    'construction': ('Construction & trades', 'البناء والحرف', ['pl', 'cz', 'hu', 'lt', 'lv', 'de', 'mt', 'ae']),
    'transport': ('Transport & logistics', 'النقل والخدمات اللوجستية', ['pl', 'cz', 'lt', 'de', 'nl', 'ae', 'ca', 'mt']),
    'health': ('Health & care', 'الصحة والرعاية', ['gb', 'ie', 'de', 'ca', 'au', 'nz', 'ae', 'fi']),
    'hospitality': ('Hospitality & food service', 'الضيافة وخدمات الطعام', ['mt', 'cy', 'pl', 'ae', 'gr', 'pt', 'ca', 'hu']),
    'services': ('Services & retail', 'الخدمات والبيع', ['ae', 'pl', 'mt', 'cy', 'sg', 'my', 'pt', 'gr']),
    'education': ('Education & childcare', 'التعليم ورعاية الأطفال', ['ae', 'kr', 'jp', 'gb', 'ca', 'sg', 'ie', 'au']),
    'domestic': ('Home & domestic', 'العمالة المنزلية', ['ae', 'sg', 'my', 'cy', 'ca', 'it', 'gr', 'tr']),
    'agrifood': ('Agriculture & food', 'الزراعة والأغذية', ['pl', 'pt', 'es', 'it', 'gr', 'nz', 'au', 'ca']),
    'industry': ('Industry & manufacturing', 'الصناعة والتصنيع', ['pl', 'cz', 'hu', 'lt', 'de', 'nl', 'ee', 'lv']),
    'office': ('Office, IT & engineering', 'المكاتب والتقنية والهندسة', ['de', 'nl', 'ie', 'ca', 'gb', 'ae', 'sg', 'ee']),
}

OCC = []


def O(oid, sector, fam, en, ar, colours, tools, uniform, ppe, setting, kw='', kwar='', fig=None, countries=None, an=False, plural=None, arp=None):
    OCC.append(dict(oid=oid, sector=sector, fam=fam, en=en, ar=ar, colours=colours, tools=tools, uniform=uniform, ppe=ppe,
                    setting=setting, kw=kw, kwar=kwar, fig=fig or {}, countries=countries, an=an, plural=plural, arp=arp))


NAVY, ORANGE, YELLOW, WHITE, HV = '#1F3A5F', '#E8701A', '#F2C230', '#F4F6F8', '#D4E62A'
# ── construction & trades ──
O('plumber', 'construction', 'trades', 'Plumber', 'سبّاك', ('#1F4E8C', '#2B6CB0', '#1F4E8C'), ['pipe-wrench', 'pipe', 'plunger'], 'Navy work coverall, cap, work gloves, safety boots', ['gloves', 'safety boots'], 'a bathroom or building plant room', 'pipes water leak bathroom installation', 'مواسير تمديدات صحية', dict(top='coverall', hat='cap', chest=['toolbelt'], glasses=False))
O('electrician', 'construction', 'trades', 'Electrician', 'كهربائي', ('#2C3E50', HV, YELLOW), ['bolt', 'multimeter', 'screwdriver'], 'Dark work shirt and trousers, hi-vis vest, insulated gloves, hard hat', ['hard hat', 'insulated gloves', 'safety glasses'], 'a building site switchboard', 'wiring cables panel installation', 'تمديدات كهربائية أسلاك', an=True)
O('welder', 'construction', 'trades', 'Welder', 'لحّام', ('#5B4636', '#3B3F45', '#3B3F45'), ['welding-mask', 'flame', 'gloves'], 'Flame-resistant leather jacket or coverall, welding helmet, heavy gloves, safety boots', ['welding helmet', 'leather gloves', 'safety boots'], 'a steel fabrication workshop with sparks', 'welding mig tig steel fabrication sparks', 'لحام حديد', dict(top='coverall', hat='skullcap', glasses=True, chest=[], gloves='tan'))
O('carpenter', 'construction', 'trades', 'Carpenter', 'نجّار', ('#7A5230', '#E8701A', '#E8A33D'), ['hammer', 'saw', 'tape-measure'], 'Work shirt, canvas apron or tool belt, hard hat on site, safety glasses', ['hard hat', 'safety glasses', 'gloves'], 'a joinery workshop or timber-frame site', 'wood joinery formwork timber', 'خشب نجارة')
O('mason', 'construction', 'trades', 'Mason', 'بنّاء', ('#6B6B6B', ORANGE, YELLOW), ['trowel', 'brick', 'level'], 'Work clothes, hi-vis vest, hard hat, gloves, safety boots', ['hard hat', 'hi-vis vest', 'gloves'], 'a building site laying blocks', 'bricklayer block laying masonry stone', 'بناء طوب حجر', plural='Masons')
O('painter', 'construction', 'trades', 'Painter', 'دهّان', (WHITE, '#B8C4CE', WHITE), ['paint-roller', 'brush', 'level'], 'White painter overalls, cap, mask when spraying', ['cap', 'dust mask', 'gloves'], 'an apartment being painted', 'painting decorator walls finishing', 'دهان طلاء جدران', dict(top='coverall', hat='cap', chest=[], gloves=None))
O('ac-technician', 'construction', 'trades', 'AC Technician', 'فني تكييف وتبريد', ('#0E6BA8', '#5DADE2', '#0E6BA8'), ['snowflake', 'gauge', 'wrench'], 'Branded work shirt or coverall, cap, gloves, tool bag', ['gloves', 'safety glasses'], 'a rooftop air-conditioning unit', 'hvac air conditioning refrigeration chiller split unit', 'تكييف تبريد صيانة', dict(top='shirt', hat='cap', chest=['toolbelt']), an=True)
O('pipe-fitter', 'construction', 'trades', 'Pipe Fitter', 'فني تركيب أنابيب', ('#34495E', ORANGE, WHITE), ['pipe', 'pipe-wrench', 'level'], 'Coverall, hi-vis vest, hard hat, gloves, safety boots', ['hard hat', 'gloves', 'safety boots'], 'an industrial plant with steel pipework', 'pipefitting industrial pipes plant oil gas', 'تمديد أنابيب')
O('steel-fixer', 'construction', 'trades', 'Steel Fixer', 'حدّاد مسلّح', ('#4A4A4A', ORANGE, YELLOW), ['rebar', 'pliers', 'gloves'], 'Work clothes, hi-vis vest, hard hat, heavy gloves, safety boots', ['hard hat', 'heavy gloves', 'safety boots'], 'a concrete site tying rebar', 'rebar reinforcement concrete ironwork', 'حديد تسليح خرسانة')
O('scaffolder', 'construction', 'trades', 'Scaffolder', 'عامل سقالات', ('#2F3B47', HV, WHITE), ['scaffold', 'wrench', 'hard-hat'], 'Work clothes, hi-vis vest, harness, hard hat, gloves', ['hard hat', 'safety harness', 'gloves'], 'scaffolding on a building facade', 'scaffolding height harness', 'سقالات عمل على ارتفاع')
O('crane-operator', 'construction', 'trades', 'Crane Operator', 'مشغّل رافعة', ('#2C3E50', ORANGE, WHITE), ['crane', 'radio', 'clipboard'], 'Work clothes, hi-vis vest, hard hat, radio', ['hard hat', 'hi-vis vest'], 'a tower crane cab over a city site', 'tower crane mobile crane rigging lifting', 'رافعة برجية', dict(chest=['radio']))
O('forklift-operator', 'construction', 'trades', 'Forklift Operator', 'سائق رافعة شوكية', ('#34495E', HV, ORANGE), ['forklift', 'pallet', 'scanner'], 'Hi-vis vest over work clothes, safety boots, gloves', ['hi-vis vest', 'safety boots'], 'a warehouse aisle with pallets', 'forklift reach truck warehouse pallets', 'رافعة شوكية مستودع', dict(hat='cap', chest=[]))
O('construction-labourer', 'construction', 'trades', 'Construction Worker', 'عامل بناء', ('#5D6D7E', ORANGE, YELLOW), ['hammer', 'brick', 'boot'], 'Work clothes, hi-vis vest, hard hat, gloves, safety boots', ['hard hat', 'hi-vis vest', 'safety boots'], 'a building site', 'general labourer helper site construction', 'عامل بناء مساعد')
O('tiler', 'construction', 'trades', 'Tiler', 'مبلّط', ('#6B5B4E', ORANGE, WHITE), ['trowel', 'level', 'tape-measure'], 'Work trousers with knee pads, T-shirt or work shirt, gloves', ['knee pads', 'gloves'], 'a new bathroom being tiled', 'tiling ceramic floor wall tiles', 'تبليط سيراميك', dict(top='shirt', hat='cap', chest=['toolbelt']))
O('plasterer', 'construction', 'trades', 'Plasterer', 'عامل جبس ومحارة', (WHITE, '#AAB7B8', WHITE), ['trowel', 'bucket', 'level'], 'White work clothes, cap, mask', ['dust mask', 'gloves'], 'an interior wall being plastered', 'plastering gypsum drywall', 'جبس محارة', dict(top='coverall', hat='cap', chest=[], gloves=None))
O('glazier', 'construction', 'trades', 'Glazier', 'فني زجاج', ('#1F618D', HV, WHITE), ['tape-measure', 'gloves', 'drill'], 'Work clothes, hi-vis vest, cut-resistant gloves, hard hat', ['cut-resistant gloves', 'hard hat'], 'a glass facade installation', 'glass windows aluminium facade', 'زجاج ألمنيوم واجهات')
O('heavy-equipment-operator', 'construction', 'trades', 'Heavy Equipment Operator', 'مشغّل معدات ثقيلة', ('#4D5656', ORANGE, YELLOW), ['crane', 'engine', 'radio'], 'Work clothes, hi-vis vest, hard hat', ['hard hat', 'hi-vis vest'], 'an excavator on an earthworks site', 'excavator bulldozer loader operator', 'حفار جرافة لودر', dict(chest=['radio']))
O('roofer', 'construction', 'trades', 'Roofer', 'عامل أسقف', ('#5B4636', ORANGE, WHITE), ['hammer', 'scaffold', 'gloves'], 'Work clothes, harness, hard hat, gloves', ['harness', 'hard hat', 'gloves'], 'a pitched roof', 'roofing tiles height', 'أسقف قرميد')
O('site-supervisor', 'construction', 'trades', 'Site Supervisor', 'مشرف موقع', ('#F4F6F8', HV, WHITE), ['clipboard', 'blueprint', 'radio'], 'Shirt, hi-vis vest, white hard hat, safety boots', ['hard hat', 'hi-vis vest'], 'a building site reviewing drawings', 'foreman supervisor site manager', 'مشرف مراقب عمال', dict(arms='clipboard', chest=['radio'], gloves=None))
# ── transport & logistics ──
O('light-driver', 'transport', 'driver', 'Light Vehicle Driver', 'سائق مركبات خفيفة', ('#1F3A5F', '#E8701A', '#1F3A5F'), ['car', 'wheel', 'route'], 'Smart polo or shirt, trousers, company ID', ['seat belt'], 'a city street behind the wheel', 'driver chauffeur car van licence', 'سائق سيارة رخصة')
O('heavy-driver', 'transport', 'driver', 'Heavy Truck Driver', 'سائق شاحنات ثقيلة', ('#2C3E50', HV, '#2C3E50'), ['truck', 'wheel', 'route'], 'Work shirt, hi-vis vest at depots, safety boots', ['hi-vis vest', 'safety boots'], 'a truck cab on the motorway', 'truck lorry hgv trailer long haul', 'شاحنة تريلا نقل ثقيل', dict(top='vest'))
O('bus-driver', 'transport', 'driver', 'Bus Driver', 'سائق حافلة', ('#EAF0F6', '#1F3A5F', '#1F3A5F'), ['bus', 'wheel', 'route'], 'Uniform shirt with company logo, tie or polo, dark trousers', [], 'a city bus', 'bus coach passenger transport', 'حافلة باص ركاب', dict(top='shirt', hat='peaked'))
O('delivery-rider', 'transport', 'driver', 'Delivery Rider', 'مندوب توصيل', ('#E8701A', '#1F3A5F', '#E8701A'), ['scooter', 'parcel', 'route'], 'Branded jacket or polo, helmet, delivery bag', ['helmet', 'gloves'], 'a motorbike in city traffic', 'delivery motorbike courier food rider', 'توصيل طلبات دراجة', dict(hat='cap', arms='crossed'))
O('courier', 'transport', 'driver', 'Courier', 'مندوب شحن', ('#C0392B', '#F2C230', '#C0392B'), ['parcel', 'truck', 'scanner'], 'Company polo, cap, scanner, comfortable shoes', [], 'a van door with parcels', 'courier parcel van delivery', 'شحن طرود', dict(arms='crossed'))
O('warehouse-worker', 'transport', 'factory', 'Warehouse Worker', 'عامل مستودع', ('#2E4053', HV, ORANGE), ['parcel', 'pallet', 'scanner'], 'Work clothes, hi-vis vest, safety boots, gloves', ['hi-vis vest', 'safety boots', 'gloves'], 'warehouse racking', 'warehouse picker packer logistics', 'مستودع تخزين', dict(top='vest', hat='cap', glasses=False))
O('taxi-driver', 'transport', 'driver', 'Taxi Driver', 'سائق تاكسي', ('#F4F6F8', '#F2C230', '#1F3A5F'), ['car', 'wheel', 'route'], 'Uniform shirt, trousers, ID card', [], 'a taxi on a city road', 'taxi cab ride', 'تاكسي أجرة', dict(top='shirt', hat='none'))
O('mechanic', 'transport', 'factory', 'Mechanic', 'ميكانيكي', ('#1F3A5F', '#C0392B', '#1F3A5F'), ['wrench', 'engine', 'oil-can'], 'Navy coverall, gloves, safety boots', ['gloves', 'safety boots'], 'a car workshop under a raised car', 'auto mechanic garage car repair', 'ميكانيكا سيارات ورشة', dict(hat='cap', glasses=False, gloves='dark'))
O('auto-electrician', 'transport', 'factory', 'Auto Electrician', 'كهربائي سيارات', ('#2C3E50', YELLOW, '#2C3E50'), ['battery', 'multimeter', 'cable'], 'Coverall, gloves, diagnostic tablet', ['gloves'], 'a car workshop with a diagnostic tool', 'auto electrical diagnostics car wiring', 'كهرباء سيارات', dict(hat='cap', glasses=False, gloves=None), an=True)
# ── health & care ──
O('nurse', 'health', 'care', 'Nurse', 'ممرض / ممرضة', ('#2E86C1', '#1B4F72', '#2E86C1'), ['stethoscope', 'syringe', 'heart-pulse'], 'Scrubs (often blue or teal), ID badge, comfortable shoes', ['gloves', 'mask'], 'a hospital ward', 'registered nurse hospital ward patient', 'تمريض مستشفى', dict(chest=['stethoscope', 'pen']))
O('caregiver', 'health', 'care', 'Caregiver', 'مقدّم رعاية', ('#48C9B0', '#117A65', '#48C9B0'), ['heart-pulse', 'wheelchair', 'first-aid'], 'Tunic or scrubs, ID badge, gloves', ['gloves'], 'a care home lounge', 'carer care assistant support worker', 'رعاية مقدم رعاية', dict(chest=['lanyard'], hat='none'), arp='مقدّمو رعاية')
O('elderly-care', 'health', 'care', 'Elderly Care Assistant', 'مساعد رعاية كبار السن', ('#AF7AC5', '#6C3483', '#AF7AC5'), ['wheelchair', 'heart-pulse', 'pill'], 'Care tunic, ID badge, comfortable shoes', ['gloves'], 'helping an older person in a care home', 'elderly care senior aged care home', 'كبار السن رعاية', dict(chest=['lanyard']), an=True)
O('doctor', 'health', 'care', 'Doctor', 'طبيب', (WHITE, '#2E86C1', WHITE), ['stethoscope', 'heart-pulse', 'clipboard'], 'White coat over shirt, stethoscope, ID badge', [], 'a clinic consultation room', 'physician gp specialist clinic', 'طبيب عيادة', dict(top='blazer', hair='short', chest=['stethoscope']))
O('pharmacist', 'health', 'care', 'Pharmacist', 'صيدلي', (WHITE, '#27AE60', WHITE), ['pill', 'clipboard', 'heart-pulse'], 'White coat, ID badge', [], 'a pharmacy counter with shelves', 'pharmacy chemist medicines', 'صيدلية أدوية', dict(top='blazer', hair='short', chest=['lanyard'], arms='clipboard'))
O('lab-technician', 'health', 'care', 'Lab Technician', 'فني مختبر', (WHITE, '#2E86C1', '#5DADE2'), ['microscope', 'test-tube', 'syringe'], 'Lab coat, gloves, safety glasses', ['gloves', 'safety glasses'], 'a clinical laboratory bench', 'laboratory analyst samples medical lab', 'مختبر تحاليل', dict(top='blazer', glasses=True, chest=[], gloves='blue'))
O('physiotherapist', 'health', 'care', 'Physiotherapist', 'أخصائي علاج طبيعي', ('#1A5276', '#5DADE2', '#1A5276'), ['heart-pulse', 'wheelchair', 'clipboard'], 'Polo or tunic, trousers, trainers', [], 'a rehab gym', 'physio rehab therapy', 'علاج طبيعي تأهيل', dict(top='polo', chest=['lanyard']))
O('dental-assistant', 'health', 'care', 'Dental Assistant', 'مساعد طبيب أسنان', ('#76D7C4', '#148F77', '#76D7C4'), ['first-aid', 'syringe', 'clipboard'], 'Scrubs, mask, gloves', ['mask', 'gloves'], 'a dental surgery', 'dental nurse dentist clinic', 'أسنان عيادة', dict(chest=['mask'], hat='scrubcap'))
O('paramedic', 'health', 'care', 'Paramedic', 'مسعف', ('#1E8449', HV, '#1E8449'), ['first-aid', 'heart-pulse', 'stethoscope'], 'Green paramedic uniform with reflective strips, boots', ['gloves'], 'an ambulance', 'ambulance emergency medical technician emt', 'إسعاف طوارئ', dict(top='vest', chest=['stethoscope', 'radio']))
# ── hospitality ──
O('chef', 'hospitality', 'hospitality', 'Chef', 'شيف', (WHITE, '#1F3A5F', WHITE), ['chef-hat', 'knife', 'pan'], 'White double-breasted chef jacket, apron, chef hat, kitchen shoes', ['hair cover', 'non-slip shoes'], 'a professional restaurant kitchen', 'chef de partie head chef restaurant kitchen', 'شيف طبخ مطعم')
O('cook', 'hospitality', 'hospitality', 'Cook', 'طبّاخ', (WHITE, '#C0392B', '#2C3E50'), ['pan', 'knife', 'chef-hat'], 'Chef jacket, apron, skull cap, non-slip shoes', ['hair cover', 'non-slip shoes'], 'a hotel or restaurant line kitchen', 'line cook commis kitchen', 'طباخ مطبخ', dict(hat='skullcap'))
O('kitchen-helper', 'hospitality', 'hospitality', 'Kitchen Helper', 'مساعد مطبخ', (WHITE, '#5D6D7E', '#5D6D7E'), ['knife', 'bucket', 'pan'], 'Kitchen whites or T-shirt, apron, hair cover, gloves', ['hair cover', 'gloves'], 'a busy kitchen prep station', 'kitchen porter steward dishwasher prep', 'مساعد مطبخ', dict(top='apron', hat='skullcap'))
O('baker', 'hospitality', 'hospitality', 'Baker', 'خبّاز', (WHITE, '#D4A373', WHITE), ['bread', 'whisk', 'chef-hat'], 'White baker jacket or T-shirt, apron, hair cover', ['hair cover'], 'a bakery with fresh bread', 'bakery pastry bread', 'مخبز خبز معجنات', dict(top='apron', hat='skullcap', sleeves='rolled'))
O('pastry-chef', 'hospitality', 'hospitality', 'Pastry Chef', 'شيف حلويات', (WHITE, '#E8A0BF', WHITE), ['whisk', 'bread', 'chef-hat'], 'Chef jacket, apron, chef hat', ['hair cover'], 'a pastry kitchen', 'pastry patisserie desserts cakes', 'حلويات معجنات')
O('waiter', 'hospitality', 'hospitality', 'Waiter / Waitress', 'نادل / نادلة', (WHITE, '#1C1C1C', '#1C1C1C'), ['tray', 'cloche', 'coffee'], 'White shirt, black waistcoat or apron, black trousers', [], 'a restaurant dining room', 'waiter waitress server restaurant', 'نادل خدمة مطعم', dict(top='apron', hat='none', arms='towel'))
O('barista', 'hospitality', 'hospitality', 'Barista', 'باريستا', ('#3E2723', '#8D6E63', '#3E2723'), ['coffee', 'cloche', 'tray'], 'T-shirt or shirt, branded apron, cap', [], 'a coffee bar with an espresso machine', 'coffee cafe espresso', 'قهوة مقهى', dict(top='apron', hat='cap'))
O('housekeeping', 'hospitality', 'retail', 'Housekeeping Attendant', 'عامل تدبير فندقي', ('#5D6D7E', '#AEB6BF', '#5D6D7E'), ['bed', 'spray', 'bucket'], 'Housekeeping tunic or dress, apron, comfortable shoes', ['gloves'], 'a hotel room being made up', 'room attendant hotel housekeeping', 'تدبير فندقي غرف', dict(top='apron', hair='bun'))
O('front-desk', 'hospitality', 'office', 'Hotel Front Desk Agent', 'موظف استقبال فندق', ('#1F3A5F', '#BE0B32', '#1F3A5F'), ['bell', 'key', 'laptop'], 'Smart suit or blazer, name badge', [], 'a hotel reception', 'receptionist front office guest service', 'استقبال فندق', dict(chest=['badge']))
O('cleaner', 'hospitality', 'retail', 'Cleaner', 'عامل نظافة', ('#117A65', '#76D7C4', '#117A65'), ['bucket', 'spray', 'broom'], 'Work tunic or polo, trousers, gloves', ['gloves'], 'an office or mall being cleaned', 'cleaning janitor facility', 'نظافة تنظيف', dict(chest=[], gloves='white'))
O('bartender', 'hospitality', 'hospitality', 'Bartender', 'بارتندر', ('#1C1C1C', '#7B241C', '#1C1C1C'), ['tray', 'cloche', 'coffee'], 'Black shirt, waistcoat or apron', [], 'a hotel bar counter', 'bar mixologist hotel bar', 'خدمة بار', dict(top='apron', hat='none'))
O('steward', 'hospitality', 'hospitality', 'Steward', 'ستيوارد مطبخ', (WHITE, '#2874A6', '#2874A6'), ['bucket', 'spray', 'tray'], 'Kitchen whites, apron, gloves, non-slip shoes', ['gloves', 'non-slip shoes'], 'a hotel kitchen dish area', 'kitchen steward dishwasher porter', 'ستيوارد غسيل صحون', dict(top='apron', hat='skullcap'))
# ── services & retail ──
O('security-guard', 'services', 'security', 'Security Guard', 'حارس أمن', ('#1C2833', '#566573', '#1C2833'), ['shield', 'radio', 'cctv'], 'Dark uniform shirt with epaulettes and badge, cap, radio', [], 'a building lobby or gate', 'security officer guard watchman', 'أمن حراسة')
O('salesperson', 'services', 'retail', 'Salesperson', 'مندوب مبيعات', ('#1F3A5F', '#BE0B32', '#BE0B32'), ['tag', 'users', 'register'], 'Branded polo or shirt, name badge', [], 'a shop floor', 'sales associate retail shop assistant', 'مبيعات معرض')
O('cashier', 'services', 'retail', 'Cashier', 'كاشير', ('#C0392B', '#F4D03F', '#C0392B'), ['register', 'scanner', 'tag'], 'Branded polo or tunic, name badge', [], 'a supermarket checkout', 'cashier checkout supermarket till', 'كاشير صندوق')
O('beautician', 'services', 'retail', 'Beautician', 'خبيرة تجميل', ('#1C1C1C', '#D98880', '#1C1C1C'), ['makeup', 'brush', 'comb'], 'Black salon tunic, apron', [], 'a beauty salon', 'beauty therapist makeup artist salon nails', 'تجميل صالون مكياج', dict(top='apron', hair='bun', chest=[]))
O('barber', 'services', 'retail', 'Barber', 'حلّاق', ('#1C1C1C', '#7F8C8D', '#1C1C1C'), ['scissors', 'comb', 'razor'], 'Barber apron or tunic', [], 'a barbershop chair', 'barber hairdresser salon grooming', 'حلاقة صالون', dict(top='apron', chest=[]))
O('tailor', 'services', 'retail', 'Tailor', 'خيّاط', ('#5D6D7E', '#E8C547', '#5D6D7E'), ['needle', 'spool', 'scissors'], 'Shirt with tape measure around the neck', [], 'a tailoring shop with fabric', 'tailor sewing alterations garment', 'خياطة تفصيل', dict(top='shirt', chest=['tape']))
O('hairdresser', 'services', 'retail', 'Hairdresser', 'مصفف شعر', ('#1C1C1C', '#B39DDB', '#1C1C1C'), ['scissors', 'comb', 'brush'], 'Salon tunic or apron', [], 'a hair salon', 'hair stylist salon', 'تصفيف شعر صالون', dict(top='apron', hair='long', chest=[]))
O('call-centre', 'services', 'office', 'Call Centre Agent', 'موظف مركز اتصال', ('#2E4053', '#5DADE2', '#2E4053'), ['headset', 'laptop', 'users'], 'Business casual, headset, ID badge', [], 'a call centre desk', 'customer service contact centre agent', 'خدمة عملاء اتصال', dict(top='polo'))
O('car-washer', 'services', 'retail', 'Car Wash Attendant', 'عامل غسيل سيارات', ('#2874A6', '#F4D03F', '#2874A6'), ['car', 'bucket', 'spray'], 'Branded polo, waterproof apron, gloves, boots', ['gloves', 'boots'], 'a car wash', 'car wash valet detailing', 'غسيل سيارات', dict(top='apron', hat='cap', gloves='blue'))
O('gardener', 'services', 'farm', 'Gardener', 'بستاني', ('#1E8449', '#7D6608', '#C9A24A'), ['sprout', 'scissors', 'bucket'], 'Work shirt, trousers or bib overall, hat, gloves', ['gloves', 'hat'], 'a landscaped garden', 'landscaping gardener irrigation', 'بستنة حدائق')
O('cleaning-supervisor', 'services', 'retail', 'Cleaning Supervisor', 'مشرف نظافة', ('#117A65', '#F4F6F8', '#117A65'), ['clipboard', 'bucket', 'users'], 'Polo or shirt, trousers, ID badge', [], 'a facility management office', 'facilities supervisor housekeeping', 'إشراف نظافة', dict(arms='clipboard'))
# ── education & childcare ──
O('teacher', 'education', 'teacher', 'Teacher', 'معلّم / معلّمة', ('#7B241C', '#F4F6F8', '#1F618D'), ['book', 'board', 'grad'], 'Smart casual: shirt, cardigan or blazer', [], 'a bright classroom', 'school teacher classroom education', 'معلم مدرسة تدريس')
O('english-teacher', 'education', 'teacher', 'English Teacher', 'معلّم لغة إنجليزية', ('#1F3A5F', '#F4F6F8', '#BE0B32'), ['book', 'abc', 'board'], 'Smart casual', [], 'a language classroom', 'esl tefl english language teacher', 'تدريس اللغة الإنجليزية', an=True)
O('kindergarten-teacher', 'education', 'teacher', 'Kindergarten Teacher', 'معلّمة رياض أطفال', ('#F5B041', '#F4F6F8', '#48C9B0'), ['abc', 'baby', 'book'], 'Comfortable smart casual, cardigan', [], 'a colourful kindergarten room', 'early years nursery preschool', 'روضة حضانة أطفال', dict(hair='bun'))
O('teaching-assistant', 'education', 'teacher', 'Teaching Assistant', 'مساعد معلّم', ('#5D6D7E', '#F4F6F8', '#F5B041'), ['book', 'abc', 'users'], 'Smart casual, ID lanyard', [], 'a classroom helping a student', 'learning support assistant school', 'مساعد تدريس')
O('nanny', 'education', 'teacher', 'Nanny / Au Pair', 'مربية أطفال', ('#E59866', '#F4F6F8', '#F5B041'), ['baby', 'abc', 'book'], 'Comfortable, neat casual clothes', [], 'a family home playroom', 'nanny au pair babysitter childcare', 'مربية رعاية أطفال', dict(hair='long', arms='crossed'))
O('trainer', 'education', 'office', 'Corporate Trainer', 'مدرّب', ('#1F3A5F', '#F4D03F', '#1F3A5F'), ['users', 'board', 'chart'], 'Business attire', [], 'a training room', 'trainer coach instructor', 'تدريب مدرب')
# ── home & domestic ──
O('housemaid', 'domestic', 'retail', 'Housemaid', 'عاملة منزلية', ('#5DADE2', '#F4F6F8', '#5DADE2'), ['broom', 'bucket', 'bed'], 'Simple uniform dress or tunic with apron', ['gloves'], 'a tidy family home', 'housemaid domestic worker housekeeper', 'عاملة منزلية خادمة', dict(top='apron', hair='bun', chest=[]))
O('domestic-helper', 'domestic', 'retail', 'Domestic Helper', 'مساعد منزلي', ('#48C9B0', '#F4F6F8', '#48C9B0'), ['broom', 'spray', 'baby'], 'Simple uniform tunic and trousers', [], 'a family home kitchen', 'helper domestic home help', 'مساعدة منزلية', dict(top='apron', hair='bun', chest=[]))
O('home-cook', 'domestic', 'hospitality', 'Family Cook', 'طبّاخ منزلي', (WHITE, '#E67E22', WHITE), ['pan', 'knife', 'bread'], 'Chef jacket or tunic, apron', ['hair cover'], 'a family kitchen', 'private cook home chef', 'طباخ منزلي', dict(hat='skullcap'))
O('family-driver', 'domestic', 'driver', 'Family Driver', 'سائق خاص', ('#F4F6F8', '#1F3A5F', '#1F3A5F'), ['car', 'wheel', 'key'], 'Smart shirt, trousers', [], 'a family car', 'private driver chauffeur family', 'سائق خاص عائلي', dict(top='shirt', hat='none'))
O('babysitter', 'domestic', 'teacher', 'Babysitter', 'جليسة أطفال', ('#F1948A', '#F4F6F8', '#F5B041'), ['baby', 'abc', 'heart-pulse'], 'Neat casual clothes', [], 'a playroom', 'babysitter childminder', 'جليسة أطفال', dict(hair='long', arms='crossed'))
# ── agriculture & food ──
O('farm-worker', 'agrifood', 'farm', 'Farm Worker', 'عامل زراعي', ('#B03A2E', '#2E4053', '#D4AC0D'), ['tractor', 'wheat', 'gloves'], 'Work shirt, overalls, hat, gloves, boots', ['gloves', 'boots', 'hat'], 'open farmland', 'agriculture farm labourer field', 'زراعة مزرعة')
O('fruit-picker', 'agrifood', 'farm', 'Fruit Picker', 'عامل قطف فواكه', ('#27AE60', '#1F3A5F', '#D4AC0D'), ['apple', 'basket', 'gloves'], 'Light work clothes, hat, gloves', ['hat', 'gloves'], 'an orchard at harvest', 'seasonal harvest picking orchard berries', 'قطف حصاد موسمي')
O('greenhouse-worker', 'agrifood', 'farm', 'Greenhouse Worker', 'عامل بيوت محمية', ('#1E8449', '#5D6D7E', '#D4AC0D'), ['sprout', 'basket', 'gloves'], 'Work clothes, gloves', ['gloves'], 'a greenhouse with plants', 'greenhouse horticulture vegetables', 'بيوت محمية زراعة')
O('butcher', 'agrifood', 'hospitality', 'Butcher', 'جزّار', (WHITE, '#922B21', WHITE), ['cleaver', 'knife', 'gloves'], 'White coat, apron, hair cover, cut-resistant glove', ['hair cover', 'cut-resistant glove'], 'a butchery counter', 'butcher meat cutter', 'جزارة لحوم', dict(top='apron', hat='skullcap'))
O('meat-processing', 'agrifood', 'factory', 'Meat Processing Worker', 'عامل تجهيز لحوم', (WHITE, '#5DADE2', WHITE), ['cleaver', 'conveyor', 'gloves'], 'White coat, apron, hairnet, boots, gloves', ['hairnet', 'boots', 'gloves'], 'a food processing line', 'meat processing plant food factory', 'تصنيع لحوم مصنع أغذية', dict(top='apron', hat='hairnet', glasses=False))
O('fish-processing', 'agrifood', 'factory', 'Fish Processing Worker', 'عامل تجهيز أسماك', (WHITE, '#2874A6', '#5DADE2'), ['fish', 'knife', 'conveyor'], 'Waterproof apron, hairnet, boots, gloves', ['hairnet', 'boots', 'gloves'], 'a seafood processing plant', 'fish seafood processing plant', 'تجهيز أسماك', dict(top='apron', hat='hairnet', glasses=False))
O('food-packer', 'agrifood', 'factory', 'Food Packer', 'عامل تعبئة أغذية', (WHITE, '#48C9B0', '#5DADE2'), ['tape-gun', 'conveyor', 'parcel'], 'White coat, hairnet, gloves', ['hairnet', 'gloves'], 'a food packing line', 'food packing production hygiene', 'تعبئة أغذية', dict(top='coverall', hat='hairnet', glasses=False))
O('dairy-worker', 'agrifood', 'farm', 'Dairy Farm Worker', 'عامل مزرعة ألبان', ('#5D6D7E', '#1F618D', '#D4AC0D'), ['tractor', 'bucket', 'gloves'], 'Work clothes, waterproof overalls, boots', ['boots', 'gloves'], 'a dairy barn', 'dairy cows milking farm', 'ألبان أبقار')
O('tractor-driver', 'agrifood', 'farm', 'Tractor Driver', 'سائق جرار', ('#B03A2E', '#2E4053', '#2E4053'), ['tractor', 'wheel', 'wheat'], 'Work clothes, cap', [], 'a tractor in a field', 'tractor operator agricultural machinery', 'جرار زراعي', dict(hat='cap', arms='wheel'))
# ── industry & manufacturing ──
O('factory-worker', 'industry', 'factory', 'Factory Worker', 'عامل مصنع', ('#1F618D', ORANGE, ORANGE), ['factory', 'conveyor', 'gear'], 'Coverall or work clothes, ear defenders, safety glasses, gloves', ['ear defenders', 'safety glasses', 'gloves'], 'a factory production floor', 'production operative manufacturing', 'عامل إنتاج مصنع')
O('production-operator', 'industry', 'factory', 'Production Operator', 'مشغّل إنتاج', ('#2E4053', HV, ORANGE), ['conveyor', 'gear', 'clipboard'], 'Work clothes, hi-vis vest, safety glasses', ['safety glasses', 'gloves'], 'a production line', 'line operator manufacturing assembly', 'خط إنتاج تشغيل', dict(top='vest'))
O('packer', 'industry', 'factory', 'Packer', 'عامل تغليف', ('#5D6D7E', HV, '#5D6D7E'), ['tape-gun', 'parcel', 'conveyor'], 'Work clothes, hi-vis vest, gloves', ['gloves'], 'a packing station', 'packing packaging warehouse', 'تغليف تعبئة', dict(top='vest', hat='none', glasses=False))
O('machine-operator', 'industry', 'factory', 'Machine Operator', 'مشغّل آلات', ('#1C2833', ORANGE, ORANGE), ['gear', 'factory', 'helmet-face'], 'Coverall, ear defenders, safety glasses, gloves', ['ear defenders', 'safety glasses', 'gloves'], 'a CNC machine shop', 'cnc lathe machinist operator', 'تشغيل آلات مخرطة')
O('assembly-worker', 'industry', 'factory', 'Assembly Line Worker', 'عامل خط تجميع', ('#1F618D', '#5DADE2', '#1F618D'), ['gear', 'screwdriver', 'conveyor'], 'Company coverall or polo, gloves, safety glasses', ['gloves', 'safety glasses'], 'an assembly line', 'assembly electronics automotive', 'تجميع خط إنتاج', an=True)
O('quality-inspector', 'industry', 'factory', 'Quality Inspector', 'مراقب جودة', (WHITE, '#1F618D', '#1F618D'), ['clipboard', 'gauge', 'gear'], 'Lab coat or coverall, safety glasses', ['safety glasses'], 'an inspection bench', 'quality control qc inspection', 'مراقبة جودة', dict(arms='clipboard', hat='none', glasses=True))
O('maintenance-technician', 'industry', 'factory', 'Maintenance Technician', 'فني صيانة', ('#1F3A5F', ORANGE, ORANGE), ['wrench', 'gear', 'toolbox'], 'Coverall, gloves, safety glasses, boots', ['gloves', 'safety glasses'], 'a plant maintenance bay', 'maintenance fitter mechanical technician', 'صيانة ميكانيكية', dict(hat='cap'))
O('cnc-operator', 'industry', 'factory', 'CNC Operator', 'مشغّل CNC', ('#2C3E50', '#5DADE2', '#2C3E50'), ['gear', 'helmet-face', 'tape-measure'], 'Coverall, safety glasses', ['safety glasses'], 'a CNC milling machine', 'cnc milling machining', 'تشغيل مكائن CNC')
O('textile-worker', 'industry', 'factory', 'Textile Worker', 'عامل نسيج', ('#AF7AC5', '#F4F6F8', '#AF7AC5'), ['needle', 'spool', 'scissors'], 'Work tunic, hair cover', [], 'a garment factory with sewing machines', 'garment sewing machinist textile', 'نسيج خياطة مصانع', dict(top='apron', hat='hairnet', glasses=False, gloves=None))
O('electronics-technician', 'industry', 'factory', 'Electronics Technician', 'فني إلكترونيات', ('#1A5276', '#5DADE2', '#1A5276'), ['multimeter', 'bolt', 'screwdriver'], 'ESD coat, wrist strap, safety glasses', ['safety glasses'], 'an electronics repair bench', 'electronics repair pcb', 'إلكترونيات صيانة', dict(hat='none', glasses=True, gloves=None), an=True)
# ── office, IT & engineering ──
O('it-specialist', 'office', 'office', 'IT Specialist', 'أخصائي تقنية معلومات', ('#2C3E50', '#5DADE2', '#2C3E50'), ['laptop', 'code', 'headset'], 'Business casual, ID lanyard', [], 'a modern office with screens', 'it support developer software network', 'تقنية معلومات برمجة', dict(arms='tablet'), an=True)
O('software-developer', 'office', 'office', 'Software Developer', 'مطوّر برمجيات', ('#1C2833', '#48C9B0', '#1C2833'), ['code', 'laptop', 'globe'], 'Smart casual, ID lanyard', [], 'a tech office', 'developer programmer engineer software', 'مطور برمجيات')
O('engineer', 'office', 'office', 'Engineer', 'مهندس', ('#1F3A5F', '#BE0B32', WHITE), ['blueprint', 'hard-hat', 'laptop'], 'Business attire; hard hat and hi-vis on site', ['hard hat on site'], 'an office with drawings or a site', 'civil mechanical electrical engineer', 'مهندس هندسة', an=True)
O('civil-engineer', 'office', 'trades', 'Civil Engineer', 'مهندس مدني', ('#F4F6F8', HV, WHITE), ['blueprint', 'level', 'clipboard'], 'Shirt, hi-vis vest, white hard hat', ['hard hat', 'hi-vis vest'], 'a construction site with drawings', 'civil engineer site structures', 'هندسة مدنية', dict(arms='clipboard', chest=[], gloves=None))
O('accountant', 'office', 'office', 'Accountant', 'محاسب', ('#212F3D', '#1F618D', '#212F3D'), ['calculator', 'chart', 'laptop'], 'Business attire', [], 'a finance office', 'accounts finance bookkeeping audit', 'محاسبة مالية', an=True)
O('admin-assistant', 'office', 'office', 'Admin Assistant', 'مساعد إداري', ('#5D6D7E', '#AF7AC5', '#5D6D7E'), ['briefcase', 'laptop', 'clipboard'], 'Business attire, ID badge', [], 'a reception or office desk', 'administrator office assistant secretary', 'سكرتارية إدارة', dict(hair='bun', arms='tablet'), an=True)
O('receptionist', 'office', 'office', 'Receptionist', 'موظف استقبال', ('#1F3A5F', '#F4D03F', '#1F3A5F'), ['bell', 'headset', 'laptop'], 'Business attire, name badge', [], 'an office reception', 'front desk receptionist', 'استقبال')
O('hr-officer', 'office', 'office', 'HR Officer', 'موظف موارد بشرية', ('#4A235A', '#F5B7B1', '#4A235A'), ['users', 'id-card', 'clipboard'], 'Business attire', [], 'a meeting room', 'human resources recruiter', 'موارد بشرية توظيف', an=True)
O('sales-executive', 'office', 'office', 'Sales Executive', 'مسؤول مبيعات', ('#1C2833', '#BE0B32', '#1C2833'), ['chart', 'briefcase', 'users'], 'Business attire', [], 'a client meeting', 'business development sales account manager', 'مبيعات تطوير أعمال')
O('architect', 'office', 'office', 'Architect', 'مهندس معماري', ('#1C1C1C', '#F4D03F', '#1C1C1C'), ['blueprint', 'laptop', 'tape-measure'], 'Smart casual', [], 'an architecture studio with models', 'architect design buildings', 'هندسة معمارية تصميم', an=True)


def article(o):
    keep = {'IT', 'AC', 'CNC', 'HR', 'English'}          # an IT specialist, an English teacher
    return ('an ' if o['an'] else 'a ') + ' '.join(w if w in keep else w.lower() for w in o['en'].split(' / ')[0].split())


def prompt(o):
    tl = ', '.join(TOOLS[t][0].lower() for t in o['tools'][:2])
    return ('Professional photograph of {person} working as ' + article(o) + ' in ' + o['setting'] + ', wearing ' + o['uniform'][0].lower() + o['uniform'][1:] +
            ', with ' + tl + ', {region} {framing}, natural light, realistic, sharp focus, friendly and confident, no text, no logos, no watermark')


def main():
    os.makedirs(os.path.join(D, 'icons'), exist_ok=True)
    os.makedirs(os.path.join(D, 'figures'), exist_ok=True)
    ids = set()
    out = []
    for o in OCC:
        assert o['oid'] not in ids, o['oid']; ids.add(o['oid'])
        assert o['fam'] in FAMILIES and o['sector'] in SECTORS, o['oid']
        for t in o['tools']:
            assert t in TOOLS, (o['oid'], t)
        fig = dict(FAMILIES[o['fam']][1]); fig.update(o['fig'])
        fig['chest'] = list(fig.get('chest', []))
        u, t, p = o['colours']
        en1 = o['en'].split(' / ')[0]
        rec = {
            'id': o['oid'], 'sector': o['sector'], 'family': o['fam'],
            'name': {'en': o['en'], 'ar': o['ar']},
            'title': {'en': en1, 'ar': o['ar'].split(' / ')[0]},
            'inSentence': article(o),                     # "Work in Poland as a welder"
            'plural': {'en': o['plural'] or (en1 + 's' if not en1.endswith(('s', 'ss')) else en1), 'ar': o['arp'] or o['ar']},
            'uniform': o['uniform'], 'ppe': o['ppe'],
            'tools': [{'id': x, 'en': TOOLS[x][0], 'ar': TOOLS[x][1], 'icon': 'icons/' + x + '.svg'} for x in o['tools']],
            'icon': 'icons/' + o['tools'][0] + '.svg',
            'colors': {'uniform': u, 'trim': t, 'ppe': p, 'accent': t if lum_ok(t) else u},
            'figure': {'family': o['fam'], 'parts': fig, 'file': 'figures/' + o['fam'] + '.svg'},
            'keywords': {'en': sorted(set((o['en'].lower().replace('/', ' ') + ' ' + o['kw']).split())), 'ar': sorted(set((o['ar'].replace('/', ' ') + ' ' + o['kwar']).split()))},
            'photo': {'search': [en1.lower(), en1.lower() + ' at work', en1.lower() + ' uniform'] + ([o['setting'].replace('a ', '', 1)] if o['setting'] else []),
                      'orientation': 'portrait', 'people': True},
            'setting': o['setting'],
            'prompt': prompt(o),
            'countries': o['countries'] or SECTORS[o['sector']][2],
        }
        out.append(rec)
    doc = {
        'app': 'kbb-studio', 'kind': 'occupations', 'version': 1,
        'note': 'Edit freely. `countries` are suggestions to start from (the routes you handle), never a statement that a country is recruiting now. '
                'No salary, visa type, contract length or benefit is stored here: ads use ‹edit› placeholders and the note "requirements and terms confirmed at consultation".',
        'promptTokens': {'{person}': 'a worker / a woman / a man / a young South Asian man ... (picked in the app)', '{region}': 'optional, e.g. "South Asian", "Filipino", "African", "Arab" -- only when the owner chooses it',
                         '{framing}': 'half-body portrait / full-body / close-up of the uniform'},
        'sectors': [{'id': k, 'en': v[0], 'ar': v[1], 'countries': v[2]} for k, v in SECTORS.items()],
        'families': [{'id': k, 'en': v[0], 'parts': v[1], 'file': 'figures/' + k + '.svg'} for k, v in FAMILIES.items()],
        'tools': [{'id': k, 'en': v[0], 'ar': v[1], 'icon': 'icons/' + k + '.svg'} for k, v in TOOLS.items()],
        'occupations': out,
    }
    with open(os.path.join(D, 'occupations.json'), 'w') as f:
        json.dump(doc, f, ensure_ascii=False, indent=1)
        f.write('\n')
    for k, v in ICONS.items():
        open(os.path.join(D, 'icons', k + '.svg'), 'w').write('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">' + v + '</svg>\n')
    # one neutral figure per family (navy / grey / navy): the reel app recolours by occupation with figureFor()
    for k, v in FAMILIES.items():
        open(os.path.join(D, 'figures', k + '.svg'), 'w').write(figure(v[1], '#1F3A5F', '#9AA6B2', '#E8701A') + '\n')
    print(len(out), 'occupations ·', len(TOOLS), 'tools ·', len(ICONS), 'icons ·', len(FAMILIES), 'figure families ·', len(SECTORS), 'sectors')


def lum_ok(h):
    from figures import lum
    return .12 < lum(h) < .8


def figure_for(rec):
    """the occupation's own figure SVG (its uniform colours) -- used by the Ad Studio build"""
    c = rec['colors']
    return figure(rec['figure']['parts'], c['uniform'], c['trim'], c['ppe'])


if __name__ == '__main__':
    main()
