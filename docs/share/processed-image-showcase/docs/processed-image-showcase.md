# Processed Image Showcase

`processPath()` now returns the legacy analysis JSON together with a cropped PNG that can be written directly with `saveTo()`. The examples below use every retail fixture and show the same three-step flow: original input, process-and-save, final saved PNG.

## Apache Jeans

<table>
  <tr>
    <th>1. Original image</th>
    <th>2. Process + save</th>
    <th>3. Final PNG output</th>
  </tr>
  <tr>
    <td><img src="../tests/Fixtures/real/retail/Apache_Jeans_38x45.png" alt="Original Apache Jeans retail fixture" width="240"></td>
    <td><img src="assets/processed-retail/cards/Apache_Jeans_38x45-workflow.svg" alt="Apache Jeans processPath plus saveTo workflow" width="320"></td>
    <td><img src="assets/processed-retail/output/Apache_Jeans_38x45-processed.png" alt="Saved Apache Jeans processed PNG" width="240"></td>
  </tr>
</table>

Crop note: `1077x1276` -> `1074x1276` PNG, saved from crop box `x=2, y=0, w=1074, h=1276`.

## Boutique Klamotte

<table>
  <tr>
    <th>1. Original image</th>
    <th>2. Process + save</th>
    <th>3. Final PNG output</th>
  </tr>
  <tr>
    <td><img src="../tests/Fixtures/real/retail/Boutique_Klamotte_35_30.png" alt="Original Boutique Klamotte retail fixture" width="240"></td>
    <td><img src="assets/processed-retail/cards/Boutique_Klamotte_35_30-workflow.svg" alt="Boutique Klamotte processPath plus saveTo workflow" width="320"></td>
    <td><img src="assets/processed-retail/output/Boutique_Klamotte_35_30-processed.png" alt="Saved Boutique Klamotte processed PNG" width="240"></td>
  </tr>
</table>

Crop note: `993x852` -> `710x429` PNG, saved from crop box `x=142, y=277, w=710, h=429`.

## Coffee Station

<table>
  <tr>
    <th>1. Original image</th>
    <th>2. Process + save</th>
    <th>3. Final PNG output</th>
  </tr>
  <tr>
    <td><img src="../tests/Fixtures/real/retail/Coffee_Station_32_33.png" alt="Original Coffee Station retail fixture" width="240"></td>
    <td><img src="assets/processed-retail/cards/Coffee_Station_32_33-workflow.svg" alt="Coffee Station processPath plus saveTo workflow" width="320"></td>
    <td><img src="assets/processed-retail/output/Coffee_Station_32_33-processed.png" alt="Saved Coffee Station processed PNG" width="240"></td>
  </tr>
</table>

Crop note: `908x937` -> `361x586` PNG, saved from crop box `x=275, y=176, w=361, h=586`.

## Decathlon

<table>
  <tr>
    <th>1. Original image</th>
    <th>2. Process + save</th>
    <th>3. Final PNG output</th>
  </tr>
  <tr>
    <td><img src="../tests/Fixtures/real/retail/Decathlon_50_55.png" alt="Original Decathlon retail fixture" width="240"></td>
    <td><img src="assets/processed-retail/cards/Decathlon_50_55-workflow.svg" alt="Decathlon processPath plus saveTo workflow" width="320"></td>
    <td><img src="assets/processed-retail/output/Decathlon_50_55-processed.png" alt="Saved Decathlon processed PNG" width="240"></td>
  </tr>
</table>

Crop note: `1419x1561` -> `949x1020` PNG, saved from crop box `x=236, y=496, w=949, h=1020`.

## E-Wheels Europe

<table>
  <tr>
    <th>1. Original image</th>
    <th>2. Process + save</th>
    <th>3. Final PNG output</th>
  </tr>
  <tr>
    <td><img src="../tests/Fixtures/real/retail/E-Wheels_Europe_45_50_All.png" alt="Original E-Wheels Europe retail fixture" width="240"></td>
    <td><img src="assets/processed-retail/cards/E-Wheels_Europe_45_50_All-workflow.svg" alt="E-Wheels Europe processPath plus saveTo workflow" width="320"></td>
    <td><img src="assets/processed-retail/output/E-Wheels_Europe_45_50_All-processed.png" alt="Saved E-Wheels Europe processed PNG" width="240"></td>
  </tr>
</table>

Crop note: `1276x1417` -> `1271x1417` PNG, saved from crop box `x=3, y=0, w=1271, h=1417`.

## Star Be Restaurant Germany

<table>
  <tr>
    <th>1. Original image</th>
    <th>2. Process + save</th>
    <th>3. Final PNG output</th>
  </tr>
  <tr>
    <td><img src="../tests/Fixtures/real/retail/STAR_BE_RESTAURANT_GERMANY_32_24.png" alt="Original Star Be Restaurant Germany retail fixture" width="240"></td>
    <td><img src="assets/processed-retail/cards/STAR_BE_RESTAURANT_GERMANY_32_24-workflow.svg" alt="Star Be Restaurant Germany processPath plus saveTo workflow" width="320"></td>
    <td><img src="assets/processed-retail/output/STAR_BE_RESTAURANT_GERMANY_32_24-processed.png" alt="Saved Star Be Restaurant Germany processed PNG" width="240"></td>
  </tr>
</table>

Crop note: `907x681` -> `617x462` PNG, saved from crop box `x=145, y=149, w=617, h=462`.

## Whole Foods

<table>
  <tr>
    <th>1. Original image</th>
    <th>2. Process + save</th>
    <th>3. Final PNG output</th>
  </tr>
  <tr>
    <td><img src="../tests/Fixtures/real/retail/Wholefoods_38x41_.png" alt="Original Whole Foods retail fixture" width="240"></td>
    <td><img src="assets/processed-retail/cards/Wholefoods_38x41_-workflow.svg" alt="Whole Foods processPath plus saveTo workflow" width="320"></td>
    <td><img src="assets/processed-retail/output/Wholefoods_38x41_-processed.png" alt="Saved Whole Foods processed PNG" width="240"></td>
  </tr>
</table>

Crop note: `1077x1162` -> `664x523` PNG, saved from crop box `x=207, y=321, w=664, h=523`.
