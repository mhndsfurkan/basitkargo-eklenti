# Basit Kargo – WooCommerce Eklentisi

Açık kaynak, hobi amaçlı geliştirilmiş WooCommerce–Basit Kargo entegrasyon eklentisi.

## Özellikler
- Özel sipariş durumları: `wc-shipped` (Kargoya Verildi), `wc-delivered` (Teslim Edildi)
- Admin sipariş listesinde bu durumların görünmesi (HPOS destekli)
- Barkod/konşimento oluşturma akışı (API entegrasyonu için hazırlıklı)
- Yönetici ve/veya müşteri e‑posta bildirimleri
- Toplu işlem ile kargoya verme

## Program Akışı (Kısaca)
1. Sipariş oluşturulur veya mevcut sipariş seçilir.
2. "Barkod oluştur" veya ilgili aksiyon tetiklenir.
3. API dönüşüne göre sipariş meta bilgileri güncellenir; durum `wc-shipped` veya `wc-delivered` yapılır.
4. Admin sipariş listesinde durum görünür; ilgili e‑postalar tetiklenir (varsa).

## Kurulum
1. Reponun ZIP’ini indirin.
2. WordPress > Eklentiler > Yeni Ekle > Eklenti Yükle > ZIP’i yükleyip etkinleştirin.
3. HPOS açıksa eklenti uyumludur.

## Kullanım İpuçları
- Toplu işlemden birden çok siparişi `Kargoya Verildi` yapabilirsiniz.
- Durumlar WooCommerce’in standart `wc-` önekiyle kaydedilir.

## Notlar
- Bu proje hobi amaçlıdır; resmi Basit Kargo eklentisi değildir.
- Güvenliğiniz için sunucu kimlik bilgilerini bu repoda tutmayın.

## Lisans
MIT
