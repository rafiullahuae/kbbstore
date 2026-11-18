// Hindi / Hinglish transliteration cases for tools/hindi-check.cjs: [Devanagari, the casual Roman spelling people
// type]. Original, CC0-1.0. Grouped so a failure says which rule broke.
'use strict';
const g = (s) => s.trim().split(/\s*[|\n]\s*/).map((p) => p.split(/\s+/));
module.exports = {
  schwa: g(`कमल kamal | कमला kamla | करता karta | अपना apna | समझना samajhna | समझते samajhte | लड़का ladka | मतलब matlab | बदलना badalna
    निकलना nikalna | रहना rehna | पहले pehle | सुबह subah | सरकार sarkar | जानता jaanta | बात baat | काम kaam | मित्र mitra | सत्य satya
    दिल dil | घर ghar | नमक namak | सड़क sadak | बचपन bachpan | अदरक adrak | कलम kalam | गरम garam | समय samay | मगर magar | अगर agar
    उठना uthna | पकड़ना pakadna | चलना chalna | खेलना khelna | बोलता bolta | सोचते sochte | जगह jagah | कमरा kamra | किताब kitaab
    मेहनत mehnat | इमारत imarat | आदमी aadmi | औरत aurat | लड़की ladki | कपड़ा kapda | पत्र patra | प्रतिशत pratishat | पसंद pasand`),
  nasal: g(`में mein | हैं hain | मैं main | हूँ hoon | हूं hoon | नहीं nahi | कहीं kahin | यहाँ yahan | वहाँ wahan | हाँ haan | करें karein
    बातें baatein | लोगों logon | हिंदी Hindi | हिन्दी Hindi | अंदर andar | अंग्रेज़ी angrezi | ठंडा thanda | संबंध sambandh | चाँद chaand
    दाँत daant | आँख aankh | गाँव gaon | माँ maa | मुँह munh | क्यों kyun | सोचें sochein | पंजाब Punjab | रंग rang | लंबा lamba`),
  nukta: g(`ज़िंदगी zindagi | जिंदगी zindagi | ज़रूर zaroor | फ़िल्म film | क़ानून kanoon | ख़ुश khush | ग़लत galat | ज़मीन zameen | सफ़र safar
    साफ़ saaf | तरफ़ taraf | बाज़ार bazaar | मज़ा maza | ज़्यादा zyada | फ़ायदा fayda | पढ़ना padhna | बढ़िया badhiya | ख़बर khabar | वक़्त waqt`),
  conjunct: g(`क्या kya | प्यार pyaar | अच्छा achha | अच्छी achhi | ज्ञान gyaan | शिक्षा shiksha | स्वागत swagat | विश्वास vishwas | द्वारा dwara
    प्रेम prem | बच्चा bachcha | पक्का pakka | सच्चा sachcha | उत्तर uttar | ध्यान dhyaan | व्यापार vyapaar | क्योंकि kyunki | इसलिए isliye
    शुक्रिया shukriya | कृपया kripya | स्थान sthaan | स्त्री stri`),
  common: g(`है hai | नहीं nahi | क्या kya | और aur | मैं main | यह yeh | वह woh | वो woh | ये ye | तो to | भी bhi | ही hi | कि ki | का ka
    के ke | की ki | को ko | से se | पर par | था tha | थी thi | आप aap | तुम tum | हम hum | कैसे kaise | कौन kaun | कब kab | कहाँ kahan
    अभी abhi | कभी kabhi | सब sab | कुछ kuch | बहुत bahut | थोड़ा thoda | ठीक theek | लेकिन lekin | फिर phir | हमेशा hamesha | मुझे mujhe
    तुम्हें tumhein | उन्हें unhein | चाहिए chahiye | वाला wala`),
  verbs: g(`करेंगे karenge | करेगा karega | समझा samjha | रहा raha | रहे rahe | कहा kaha | बताऊँगा bataunga | जाएगा jayega | आएगी aayegi
    खाया khaya | बनाता banata | बोलो bolo | देखिए dekhiye | सुनो suno | मिलेंगे milenge | लगता lagta | सकते sakte | जानते jaante | पाएं payein`),
  loan: g(`जॉब job | वीज़ा visa | वीजा visa | कनाडा Canada | अप्लाई apply | ऑनलाइन online | प्रोसेस process | ईज़ी easy | स्किन skin | सीरम serum
    ग्लोइंग glowing | सनस्क्रीन sunscreen | मॉइस्चराइज़र moisturizer | प्रोडक्ट product | ऑफ़र offer | डिस्काउंट discount | कंसल्टेशन consultation
    फ्री free | टीम team | यूनिवर्सिटी university | स्टूडेंट student | वर्क work | परमिट permit | एम्बेसी embassy | डॉक्यूमेंट्स documents | सैलरी salary
    कंपनी company | ऑफिस office | फ़ोन phone | मोबाइल mobile | इंस्टाग्राम Instagram | व्हाट्सएप WhatsApp | दुबई Dubai | जर्मनी Germany | यूरोप Europe
    डॉक्टर doctor | इंटरव्यू interview | सब्सक्राइब subscribe | लाइक like | शेयर share | ऑर्डर order`),
  marks: [['है।', 'hai.'], ['क्या?', 'kya?'], ['१२', '12'], ['२०२६', '2026'], ['"नहीं"', '"nahi"'], ['दोस्तों,', 'doston,']],
  sentences: [
    ['आप कैसे हो? कनाडा में जॉब के लिए अप्लाई करो', 'Aap kaise ho? Canada mein job ke liye apply karo'],
    ['यह सीरम आपकी स्किन को ग्लोइंग बनाता है।', 'Yeh serum aapki skin ko glowing banata hai.'],
    ['मैं आज बहुत खुश हूँ', 'Main aaj bahut khush hoon'],
    ['हमारी टीम आपकी मदद करेगी', 'Hamari team aapki madad karegi'],
    ['क्या आप जानते हैं कि वीज़ा प्रोसेस बहुत ईज़ी है?', 'Kya aap jaante hain ki visa process bahut easy hai?'],
    ['दोस्तों, आज मैं आपको बताऊँगा कि यूरोप में वर्क परमिट कैसे मिलता है।', 'Doston, aaj main aapko bataunga ki Europe mein work permit kaise milta hai.'],
    ['ज़िंदगी में मेहनत ज़रूरी है', 'Zindagi mein mehnat zaroori hai'],
    ['अभी ऑर्डर करें और २० प्रतिशत डिस्काउंट पाएं', 'Abhi order karein aur 20 pratishat discount payein'],
    ['हम आपकी मदद कर सकते हैं।', 'Hum aapki madad kar sakte hain.'],
    ['मुझे यह प्रोडक्ट बहुत पसंद आया', 'Mujhe yeh product bahut pasand aaya'],
  ],
  // HELD OUT: written after the rules were final and never tuned on — measured, reported as accuracy (a / b = either)
  heldOut: g(`कमज़ोर kamzor | मुसाफ़िर musafir | अख़बार akhbaar/akhbar | इंतज़ार intezaar/intezar | तैयार taiyaar/taiyar/tayyar | बिस्तर bistar
    खिड़की khidki | चश्मा chashma | दरवाज़ा darwaza/darwaaza | पतंग patang | मछली machhli/machli | बंदर bandar | कबूतर kabootar/kabutar | गुलाब gulaab/gulab
    समंदर samandar | पहाड़ pahaad/pahad | जंगल jangal | सितारा sitara/sitaara | आसमान aasmaan/aasman/asmaan | किसान kisaan/kisan | मज़दूर mazdoor
    सिपाही sipahi/sipaahi | बादशाह baadshah/badshah | ख़ज़ाना khazana/khazaana | निशान nishaan/nishan | मुस्कान muskaan/muskan | शर्मीला sharmila/sharmeela
    चमकीला chamkeela/chamkila | नीला neela | पीला peela | हरा hara | गुलाबी gulabi/gulaabi | सुनहरा sunehra/sunahra | बेहतरीन behtareen/behatareen
    ज़बरदस्त zabardast | शानदार shandaar/shaandaar/shandar | लाजवाब lajawaab/lajawab | मज़बूत mazboot | ख़तरनाक khatarnaak/khatarnak | तितली titli | बरसात barsaat/barsat
    मिठाई mithai | पतला patla | मोटा mota | ऊँचा ooncha/uncha | गहरा gehra/gahra | कोयल koyal | चिड़िया chidiya | हाथी haathi/hathi | घोड़ा ghoda
    तलवार talwaar/talwar | दीवार deewar/diwar/deewaar | बिजली bijli | सोना sona | चाँदी chaandi/chandi | लोहा loha | कागज़ kaagaz/kagaz`),
  // Urdu script -> Roman Urdu, and Devanagari -> Urdu script
  urduRoman: g(`یہ yeh | ہے hai | نہیں nahi | کیا kya | اور aur | بہت bahut | ضرورت zaroorat | خوش khush | ویزا visa | کینیڈا Canada
    جاب job | اپلائی apply | کریں karein | مدد madad | شکریہ shukriya | زندگی zindagi | آپ aap | ہم hum | کیسے kaise | سکتے sakte`),
  devaUrdu: g(`है ہے | हैं ہیں | नहीं نہیں | क्या کیا | और اور | ज़िंदगी زندگی | बहुत بہت | मदद مدد | आप آپ | खुश خوش | ज़रूरत ضرورت | कैसे کیسے
    करें کریں | हम ہم | सकते سکتے | बनाता بناتا | दुनिया دنیا | शुक्रिया شکریہ`),
};
