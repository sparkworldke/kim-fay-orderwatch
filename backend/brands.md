# Official brand lists (filters + inventory brand seeder)

Canonical names match `Products-With Brands.csv` and the Partner / Manufactured filters.

## PARTNER (trading)

- Airoma
- Aptamil
- Bio Oil
- Cow & Gate
- Dabur
- Dermoviva
- Dove
- Duracell
- Fem
- Hobby
- Huggies
- Kotex
- Lux
- Miswak
- Ors
- Rexona
- Vatika

## MANUFACTURED (Kimfay)

- Cosy
- Cosy Poa
- Fay
- Kleenex
- Sifa
- Tishu Poa
- Ultra Clean
- Kimfay

## Seeder

```bash
cd backend
php artisan db:seed --class=InventoryBrandSeeder
```

Optional path override:

```bash
php artisan db:seed --class=InventoryBrandSeeder
# uses database/seeders/data/products-with-brands.csv by default
```

Source file: `database/seeders/data/products-with-brands.csv`  
(from repo root `Products-With Brands.csv`).


connect to acumatica and let me know if we have this quanties per warehous I want to introduce the name ing Qnty on Hand and Quantity Available
COSTP0030 quanties in the warehouses, 
FGS
on hand 1297
avalable - 1126
MSA
on hand 173
avalable173
pfgs
on hand 9
avalable9
fgs 3
on hand  0
avalable 0
Tpfgs
on hand  2639
avalable 2639




expose
warehouse
QtyAvailable



I want to create a UI with good UX for executive for our inventory


1. we have manufactured and partner brands we distribute

Manufactured are items produced/manufacture by Kimfay and we have the brands 

Partner or Trading are item we distribute for other companies

## PARTNER (trading)

- Airoma
- Aptamil
- Bio Oil
- Cow & Gate
- Dabur
- Dermoviva
- Dove
- Duracell
- Fem
- Hobby
- Huggies
- Kotex
- Lux
- Miswak
- Ors
- Rexona
- Vatika

## MANUFACTURED (Kimfay)

- Cosy
- Cosy Poa
- Fay
- Kleenex
- Sifa
- Tishu Poa
- Ultra Clean
- Kimfay


The 2 dashboard should be similar but different funtionalities.

on the table have these filters
- Status (health, Critcal, critical) this is measured vs MSI column
- on Manufactured dashboard add the brand filter on the table (default all selected)
- on the Table compare with Sales from past 3 Months and work on the Run Rate Vs stocks available how long witll it last and status? (Healthy etc)
- As a super Admin/Production Manager/COO I should be able to add the MSI


///

partner brands however, the filters at the top should be stocks, sales, business line, warehouse, select brands (multi select),


Add dummy data for us to test the interactions

We have a shared sales dashboard - remememebt thes are all volumes not revenue

See the screenshots




