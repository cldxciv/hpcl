-- Member photo and the private share-kit link. Run once (bin/migrate-membership-share.php does it
-- safely and can be re-run): the applicant's optional photo, a secret token for the page where a
-- paid member downloads their social-media creative, and the photo on the public member profile.
ALTER TABLE membership_applications
    ADD COLUMN photo VARCHAR(512) DEFAULT NULL AFTER message,
    ADD COLUMN share_token VARCHAR(40) DEFAULT NULL AFTER photo,
    ADD UNIQUE KEY uq_membership_share_token (share_token);
ALTER TABLE members ADD COLUMN photo VARCHAR(512) DEFAULT NULL AFTER logo;
