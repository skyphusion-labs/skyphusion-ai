# Translate

A theme or plugin calls `skyphusion_ai_translate()`.
The first argument is the text.
The second argument is the target language code.
The third argument is the source language code.
An empty source means English.
The same source and target returns the text.
That call does not leave the site.

English into one of 22 scheduled languages of India uses `@cf/ai4bharat/indictrans2-en-indic-1B`.
That model is IndicTrans2.
Any other pair uses `@cf/meta/m2m100-1.2b`.
That model is M2M100.
Neither model is in the writing catalog.
The text job has no model choice.

The request sends the caller text.
It does not send the translation prompt.
IndicTrans2 sends `target_language`.
M2M100 sends `target_lang`.
M2M100 sends `source_lang` only when the source is set.

The call counts as text.
The call stops when the daily neuron budget is spent.
The error text does not include the API token.

IndicTrans2 parameters are at https://developers.cloudflare.com/workers-ai/models/indictrans2-en-indic-1B/.
M2M100 parameters are at https://developers.cloudflare.com/workers-ai/models/m2m100-1.2b/.

A code of two letters is accepted.
Six languages use a three letter code.
Those codes are brx, doi, kok, mai, mni, and sat.
A language name is not a code.

The 22 codes are:

- as is Assamese, asm_Beng.
- bn is Bengali, ben_Beng.
- brx is Bodo, brx_Deva.
- doi is Dogri, doi_Deva.
- gu is Gujarati, guj_Gujr.
- hi is Hindi, hin_Deva.
- kn is Kannada, kan_Knda.
- kok is Konkani, gom_Deva.
- ks is Kashmiri, kas_Deva.
- mai is Maithili, mai_Deva.
- ml is Malayalam, mal_Mlym.
- mni is Manipuri, mni_Beng.
- mr is Marathi, mar_Deva.
- ne is Nepali, npi_Deva.
- or is Odia, ory_Orya.
- pa is Punjabi, pan_Guru.
- sa is Sanskrit, san_Deva.
- sat is Santali, sat_Olck.
- sd is Sindhi, snd_Deva.
- ta is Tamil, tam_Taml.
- te is Telugu, tel_Telu.
- ur is Urdu, urd_Arab.

Kashmiri uses Devanagari.
Sindhi uses Devanagari.
Manipuri uses Bengali.
