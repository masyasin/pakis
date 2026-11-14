/*
 Potongan konsep/region.sql (format Sintech) untuk test & verifikasi T-012:
 Jawa Tengah > Kab. Kudus (semua kecamatan, desa Undaan & Kota Kudus) + Kab. Jepara (1 kecamatan, 2 desa).
 Urutan tabel sama dengan berkas asli (kab/kota sebelum provinsi). Data wilayah publik, bukan data pegawai.
*/

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Records of regioncity
INSERT INTO `regioncity` VALUES ('3319', '33', 'Kabupaten Kudus');
INSERT INTO `regioncity` VALUES ('3320', '33', 'Kabupaten Jepara');
-- Records of regiondistrict
INSERT INTO `regiondistrict` VALUES ('331901', '3319', 'Kaliwungu');
INSERT INTO `regiondistrict` VALUES ('331902', '3319', 'Kota Kudus');
INSERT INTO `regiondistrict` VALUES ('331903', '3319', 'Jati');
INSERT INTO `regiondistrict` VALUES ('331904', '3319', 'Undaan');
INSERT INTO `regiondistrict` VALUES ('331905', '3319', 'Mejobo');
INSERT INTO `regiondistrict` VALUES ('331906', '3319', 'Jekulo');
INSERT INTO `regiondistrict` VALUES ('331907', '3319', 'Bae');
INSERT INTO `regiondistrict` VALUES ('331908', '3319', 'Gebog');
INSERT INTO `regiondistrict` VALUES ('331909', '3319', 'Dawe');
INSERT INTO `regiondistrict` VALUES ('332001', '3320', 'Kedung');
-- Records of regionprovince
INSERT INTO `regionprovince` VALUES ('33', 'Jawa Tengah');
-- Records of regionvillage
INSERT INTO `regionvillage` VALUES ('3319021001', '331902', 'Purwosari', '59316');
INSERT INTO `regionvillage` VALUES ('3319021004', '331902', 'Sunggingan', '59317');
INSERT INTO `regionvillage` VALUES ('3319021005', '331902', 'Panjunan', '59317');
INSERT INTO `regionvillage` VALUES ('3319021006', '331902', 'Wergu Wetan', '59318');
INSERT INTO `regionvillage` VALUES ('3319021007', '331902', 'Wergu Kulon', '59318');
INSERT INTO `regionvillage` VALUES ('3319021008', '331902', 'Mlati Kidul', '59319');
INSERT INTO `regionvillage` VALUES ('3319021009', '331902', 'Mlatinorowito', '59319');
INSERT INTO `regionvillage` VALUES ('3319021017', '331902', 'Kerjasan', '59315');
INSERT INTO `regionvillage` VALUES ('3319021018', '331902', 'Kajeksan', '59314');
INSERT INTO `regionvillage` VALUES ('3319022002', '331902', 'Janggalan', '59316');
INSERT INTO `regionvillage` VALUES ('3319022003', '331902', 'Demangan', '59317');
INSERT INTO `regionvillage` VALUES ('3319022010', '331902', 'Mlati Lor', '59319');
INSERT INTO `regionvillage` VALUES ('3319022011', '331902', 'Nganguk', '59311');
INSERT INTO `regionvillage` VALUES ('3319022012', '331902', 'Kramat', '59312');
INSERT INTO `regionvillage` VALUES ('3319022013', '331902', 'Demaan', '59313');
INSERT INTO `regionvillage` VALUES ('3319022014', '331902', 'Langgardalem', '59315');
INSERT INTO `regionvillage` VALUES ('3319022015', '331902', 'Kauman', '59315');
INSERT INTO `regionvillage` VALUES ('3319022016', '331902', 'Damaran', '59316');
INSERT INTO `regionvillage` VALUES ('3319022019', '331902', 'Krandon', '59314');
INSERT INTO `regionvillage` VALUES ('3319022020', '331902', 'Singocandi', '59314');
INSERT INTO `regionvillage` VALUES ('3319022021', '331902', 'Glantengan', '59313');
INSERT INTO `regionvillage` VALUES ('3319022022', '331902', 'Kaliputu', '59312');
INSERT INTO `regionvillage` VALUES ('3319022023', '331902', 'Barongan', '59312');
INSERT INTO `regionvillage` VALUES ('3319022024', '331902', 'Burikan', '59311');
INSERT INTO `regionvillage` VALUES ('3319022025', '331902', 'Rendeng', '59311');
INSERT INTO `regionvillage` VALUES ('3319042001', '331904', 'Wonosoco', '59372');
INSERT INTO `regionvillage` VALUES ('3319042002', '331904', 'Lambangan', '59372');
INSERT INTO `regionvillage` VALUES ('3319042003', '331904', 'Kalirejo', '59372');
INSERT INTO `regionvillage` VALUES ('3319042004', '331904', 'Medini', '59372');
INSERT INTO `regionvillage` VALUES ('3319042005', '331904', 'Sambung', '59372');
INSERT INTO `regionvillage` VALUES ('3319042006', '331904', 'Glagahwaru', '59372');
INSERT INTO `regionvillage` VALUES ('3319042007', '331904', 'Kutuk', '59372');
INSERT INTO `regionvillage` VALUES ('3319042008', '331904', 'Undaan Kidul', '59372');
INSERT INTO `regionvillage` VALUES ('3319042009', '331904', 'Undaan Tengah', '59372');
INSERT INTO `regionvillage` VALUES ('3319042010', '331904', 'Karangrowo', '59372');
INSERT INTO `regionvillage` VALUES ('3319042011', '331904', 'Larikrejo', '59372');
INSERT INTO `regionvillage` VALUES ('3319042012', '331904', 'Undaan Lor', '59372');
INSERT INTO `regionvillage` VALUES ('3319042013', '331904', 'Wates', '59372');
INSERT INTO `regionvillage` VALUES ('3319042014', '331904', 'Ngemplak', '59372');
INSERT INTO `regionvillage` VALUES ('3319042015', '331904', 'Terangmas', '59372');
INSERT INTO `regionvillage` VALUES ('3319042016', '331904', 'Berugenjang', '59372');
INSERT INTO `regionvillage` VALUES ('3320012001', '332001', 'Kedungmalang', '59463');
INSERT INTO `regionvillage` VALUES ('3320012002', '332001', 'Kalianyar', '59463');

SET FOREIGN_KEY_CHECKS = 1;
