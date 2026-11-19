#!/usr/bin/env python3
"""Write satelitmoldova/translations/ro.php and ru.php (PrestaShop classic module translations).

    python tools/translations.py           write the files
    python tools/translations.py --check   fail when a source string has no translation (CI)

Keys are '<{satelitmoldova}prestashop>' + source file name + '_' + md5(English text),
as PrestaShop builds them for $this->l() and {l s='' mod='satelitmoldova'}.
"""
import hashlib
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
MOD = ROOT / "satelitmoldova"

T = {
    "Satelit Moldova": ("Satelit Moldova", "Satelit Moldova"),
    "BNM exchange rates for your currencies and market prices from Moldovan shops on product pages, through the Satelit API.": (
        "Cursurile BNM pentru valutele magazinului și prețurile din magazinele Moldovei pe pagina produsului, prin API-ul Satelit.",
        "Курсы НБМ для валют магазина и цены магазинов Молдовы на странице товара через API Satelit."),
    "Remove the Satelit settings, cache and search terms?": (
        "Ștergi setările Satelit, cache-ul și termenii de căutare?", "Удалить настройки Satelit, кэш и поисковые запросы?"),
    "You do not have permission to configure this module.": (
        "Nu ai dreptul să configurezi acest modul.", "У вас нет прав на настройку этого модуля."),
    "A Satelit key starts with sat_ (for example sat_live_...).": (
        "O cheie Satelit începe cu sat_ (de exemplu sat_live_...).", "Ключ Satelit начинается с sat_ (например sat_live_...)."),
    "%s: a whole number from %d to %d.": ("%s: un număr întreg de la %d la %d.", "%s: целое число от %d до %d."),
    "Settings saved.": ("Setările au fost salvate.", "Настройки сохранены."),
    "Enter an API key first.": ("Introdu întâi cheia API.", "Сначала введите ключ API."),
    "The key works, but its account has no plan: choose one at developer.satelit.md/en/pricing/.": (
        "Cheia merge, dar contul n-are un plan: alege unul la developer.satelit.md/en/pricing/.",
        "Ключ работает, но у аккаунта нет тарифа: выберите его на developer.satelit.md/en/pricing/."),
    "Connected: %s plan, %s of %s credits left, resets %s.": (
        "Conectat: planul %s, %s din %s credite rămase, se reînnoiesc la %s.",
        "Подключено: тариф %s, осталось %s из %s кредитов, обновление %s."),
    "Rates: refresh after (hours)": ("Cursuri: actualizare după (ore)", "Курсы: обновлять через (часов)"),
    "Market prices: cache for (hours)": ("Prețuri pe piață: păstrate (ore)", "Цены на рынке: хранить (часов)"),
    "Shops shown": ("Magazine afișate", "Сколько магазинов показывать"),
    "Language of the shopper": ("Limba cumpărătorului", "Язык покупателя"),
    "Settings": ("Setări", "Настройки"),
    "Keys: developer.satelit.md/en/console/ · plans: developer.satelit.md/en/pricing/. The key stays on the server: shop pages never see it.": (
        "Chei: developer.satelit.md/en/console/ · planuri: developer.satelit.md/en/pricing/. Cheia rămâne pe server: paginile magazinului n-o văd.",
        "Ключи: developer.satelit.md/en/console/ · тарифы: developer.satelit.md/en/pricing/. Ключ хранится на сервере: страницы магазина его не видят."),
    "API key": ("Cheia API", "Ключ API"),
    "A key is saved. Leave this field empty to keep it.": (
        "O cheie e salvată. Lasă câmpul gol ca s-o păstrezi.", "Ключ сохранён. Оставьте поле пустым, чтобы его сохранить."),
    "Data language": ("Limba datelor", "Язык данных"),
    "The cron URL asks the API only when the last rates are older than this (1 credit per update).": (
        "Adresa pentru cron cere API-ul doar când cursurile sunt mai vechi de atât (1 credit pe actualizare).",
        "Адрес для cron обращается к API, только когда курсы старше этого (1 кредит за обновление)."),
    "Market prices on product pages": ("Prețul pe piață pe pagina produsului", "Цена на рынке на странице товара"),
    "Yes": ("Da", "Да"),
    "No": ("Nu", "Нет"),
    "Shown under the add to cart button. Each product can have its own search term (below, or in the product page, Modules tab); otherwise its name is used. One lookup costs 5 credits and is cached.": (
        "Apare sub butonul de adăugare în coș. Fiecare produs poate avea termenul său de căutare (mai jos sau în pagina produsului, fila Module); altfel se folosește numele. O căutare costă 5 credite și se păstrează în cache.",
        "Показывается под кнопкой «В корзину». У каждого товара может быть свой поисковый запрос (ниже или на странице товара, вкладка «Модули»), иначе берётся название. Один поиск стоит 5 кредитов и кэшируется."),
    "No product with this ID.": ("Nu există un produs cu acest ID.", "Нет товара с таким ID."),
    "Search term saved.": ("Termenul de căutare a fost salvat.", "Поисковый запрос сохранён."),
    # views/templates/admin/terms.tpl
    "Search terms by product": ("Termeni de căutare pe produse", "Поисковые запросы по товарам"),
    "Words that name a product in Moldovan shops, for the market price block. A product without a term is searched by its name. An empty term removes it.": (
        "Cuvintele care numesc un produs în magazinele din Moldova, pentru blocul „Prețul pe piață”. Un produs fără termen se caută după nume. Un termen gol îl șterge.",
        "Слова, которыми товар называется в магазинах Молдовы, для блока «Цена на рынке». Товар без запроса ищется по названию. Пустой запрос удаляет его."),
    "Product ID": ("ID produs", "ID товара"),
    "Product": ("Produs", "Товар"),
    "Save": ("Salvează", "Сохранить"),
    "Test connection": ("Verifică legătura", "Проверить подключение"),
    "Update rates now": ("Actualizează cursurile acum", "Обновить курсы сейчас"),
    # views/templates/admin/info.tpl
    "BNM exchange rates": ("Cursurile BNM", "Курсы НБМ"),
    "Sets the conversion rate of every shop currency BNM publishes (EUR, USD, RON, RUB, UAH...) from the official MDL rates, whatever your default currency is.": (
        "Pune cursul fiecărei valute a magazinului pe care o publică BNM (EUR, USD, RON, RUB, UAH...) din cursurile oficiale în MDL, oricare ar fi valuta implicită.",
        "Задаёт курс каждой валюты магазина, которую публикует НБМ (EUR, USD, RON, RUB, UAH...), по официальным курсам к MDL, какой бы ни была основная валюта."),
    "Default currency:": ("Valuta implicită:", "Основная валюта:"),
    "Add the Moldovan leu (MDL) under International > Localization > Currencies to show market prices in your currencies.": (
        "Adaugă leul moldovenesc (MDL) în Internațional > Localizare > Valute ca prețurile pe piață să apară în valutele tale.",
        "Добавьте молдавский лей (MDL) в Международное > Локализация > Валюты, чтобы цены на рынке показывались в ваших валютах."),
    "Last update": ("Ultima actualizare", "Последнее обновление"),
    "Not yet": ("Încă nu", "Ещё нет"),
    "Cron URL": ("Adresa pentru cron", "Адрес для cron"),
    "Call it every hour; it asks the API only when the last rates are older than the TTL. Turn off the live exchange rates of PrestaShop so they do not overwrite these.": (
        "Apeleaz-o la fiecare oră; cere API-ul doar când cursurile sunt mai vechi decât TTL-ul. Oprește cursurile live ale PrestaShop ca să nu le suprascrie.",
        "Вызывайте раз в час; запрос к API идёт, только когда курсы старше TTL. Отключите живые курсы PrestaShop, чтобы они их не перезаписывали."),
    # views/templates/admin/product_tab.tpl
    "Satelit search term": ("Termen de căutare Satelit", "Поисковый запрос Satelit"),
    "e.g. iPhone 15 128GB": ("de ex. iPhone 15 128GB", "например iPhone 15 128GB"),
    "Words that name this product in Moldovan shops, for the market price block. Empty: the product name.": (
        "Cuvintele care numesc produsul în magazinele din Moldova, pentru blocul „Prețul pe piață”. Gol: numele produsului.",
        "Слова, которыми товар называется в магазинах Молдовы, для блока «Цена на рынке». Пусто: название товара."),
    # views/templates/hook/market.tpl
    "Market price": ("Prețul pe piață", "Цена на рынке"),
    "from": ("de la", "от"),
    "median": ("mediana", "медиана"),
    "in %d shops": ("în %d magazine", "в %d магазинах"),
    "Compared with:": ("Comparat cu:", "Сравнение с:"),
    "out of stock": ("stoc epuizat", "нет в наличии"),
    "Data: Satelit": ("Date: Satelit", "Данные: Satelit"),
}


def sources():
    """(file key, English text) for every translatable string."""
    out = []
    php = (MOD / "satelitmoldova.php").read_text(encoding="utf-8")
    out += [("satelitmoldova", s) for s in re.findall(r"\$this->l\('((?:[^'\\]|\\.)*)'\)", php)]
    for tpl in sorted(MOD.glob("views/templates/**/*.tpl")):
        text = tpl.read_text(encoding="utf-8")
        out += [(tpl.stem, s) for s in re.findall(r"\{l s='((?:[^'\\]|\\.)*)'", text)]
    return sorted(set(out))


def php_quote(s):
    return "'" + s.replace("\\", "\\\\").replace("'", "\\'") + "'"


def main():
    missing = [s for _, s in sources() if s not in T]
    if missing:
        print("no translation for:", *missing, sep="\n  ")
        return 1
    if "--check" in sys.argv:
        print(f"{len(sources())} strings translated")
        return 0
    for i, lang in enumerate(("ro", "ru")):
        lines = ["<?php", "", "global $_MODULE;", "$_MODULE = array();"]
        for key, s in sources():
            k = f"<{{satelitmoldova}}prestashop>{key}_" + hashlib.md5(s.encode("utf-8")).hexdigest()
            lines.append(f"$_MODULE[{php_quote(k)}] = {php_quote(T[s][i])};")
        (MOD / "translations" / f"{lang}.php").write_bytes(("\n".join(lines) + "\n").encode("utf-8"))
        print(f"translations/{lang}.php  {len(lines) - 4} strings")
    return 0


if __name__ == "__main__":
    sys.exit(main())
