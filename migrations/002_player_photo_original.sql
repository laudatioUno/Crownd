-- Crownd 1.1.0: keep the original of a player photo and the chosen cut-out,
-- so the cut-out can be changed later without uploading the photo again.
ALTER TABLE crownd_players
    ADD COLUMN photo_original VARCHAR(40) NULL AFTER photo_file,
    ADD COLUMN photo_crop VARCHAR(80) NULL AFTER photo_original;
