-- Hand-maintained Fishman (Gurgler) species traits for bio_random.
-- Name rows match the current, bracketed or original name; race rows cover renamed Fishmen.
-- King Gurgler keeps his bio_unique profile, which always wins over bio_random.
BEGIN;

-- Retire the older Gurgler occupation that described Fog Islands cannibals.
DELETE FROM bio_random AS old_row
WHERE old_row.type = 'occupation'
  AND old_row.name = 'Gurgler'
  AND old_row.description = 'A wretched cannibal dwelling in the Fog Islands who hunts lost travelers. Emits disturbing sounds while stalking prey through the mist.'
  AND EXISTS (
      SELECT 1
      FROM bio_random AS current_row
      WHERE current_row.type = old_row.type
        AND current_row.name = old_row.name
        AND current_row.description = 'A pack hunter and nest scavenger of Fishman Island, south of Catun. It ambushes travelers in numbers, fights with its claws, and drags the wounded back to its nest to be eaten alive.'
  );

-- Update in place so a base row's enabled state is preserved.
UPDATE bio_random
SET description = 'A pack hunter and nest scavenger of Fishman Island, south of Catun. It ambushes travelers in numbers, fights with its claws, and drags the wounded back to its nest to be eaten alive.',
    updated_at = NOW()
WHERE type = 'occupation'
  AND name = 'Gurgler'
  AND description = 'A wretched cannibal dwelling in the Fog Islands who hunts lost travelers. Emits disturbing sounds while stalking prey through the mist.';

WITH source_rows(type, name, race, description) AS (
    VALUES
        ('personality', 'Gurgler', '', 'Skittish alone but frenzied in a pack, a Gurgler is ruled by hunger, nest instinct and the pull of its kin. It has no interest in trade, talk or bargains, only in whether a stranger is prey, a threat, or something to drag back to the nest.'),
        ('backstory', 'Gurgler', '', 'Hatched from a clutch of eggs in the nests of Fishman Island, south of the Bonefields and Catun. It has spent its whole life roaming the island''s shallows and ancient ruins under mad rain and acid downpours, hunting with its pack beneath the Alpha Gurglers and King Gurgler.'),
        ('speechstyle', 'Gurgler', '', 'Cannot speak any human language and never forms words. It communicates only through wet gurgles, throaty croaks, clicks and hisses that rise to frantic burbling as the pack closes in, and through body language: a low crouch, spread claws, jerks of the head toward prey and swaying in time with its packmates.'),
        ('occupation', 'Gurgler', '', 'A pack hunter and nest scavenger of Fishman Island, south of Catun. It ambushes travelers in numbers, fights with its claws, and drags the wounded back to its nest to be eaten alive.'),
        ('appearance', 'Gurgler', '', 'A hunched, fish-like humanoid with slick, damp skin, a wide gaping mouth and long clawed hands. It moves in a low, loping crouch and is rarely seen without others of its kind close behind.'),
        ('goals', 'Gurgler', '', 'To feed, guard the nests and their eggs, and overwhelm intruders through sheer numbers. Wounded prey is carried home to the nest rather than left behind.'),

        ('personality', 'Alpha Gurgler', '', 'Bolder and more aggressive than common Gurglers, an Alpha Gurgler is the first to charge and the last to abandon a kill. It asserts dominance over its pack through size and violence and treats any stranger on its shore as a challenge or a meal.'),
        ('backstory', 'Alpha Gurgler', '', 'A larger, stronger Fishman that rose above the common Gurglers of Fishman Island through size and savagery. It leads hunting packs through the ruins and shallows south of Catun and answers only to King Gurgler in the Island Lab.'),
        ('speechstyle', 'Alpha Gurgler', '', 'Cannot speak any human language and never forms words. Its voice is a deep, booming gurgle and harsh croaking bark that rallies the pack and drives lesser Gurglers forward, backed by body language: rearing to full height, sweeping its claws, snapping its jaws and shoving packmates into position.'),
        ('occupation', 'Alpha Gurgler', '', 'The dominant member of a Gurgler pack, leading through savage strength and territorial aggression. This creature commands lesser Gurglers in their hunting grounds with brutal efficiency.'),
        ('appearance', 'Alpha Gurgler', '', 'A towering, heavily built fish-like humanoid, noticeably larger than common Gurglers, with thick limbs, wide jaws and long, heavy claws. Its hunched bulk and the way lesser Gurglers give it room mark it as the leader of the pack.'),
        ('goals', 'Alpha Gurgler', '', 'To lead the pack to prey, defend the nests from intruders and keep the lesser Gurglers in line. Every captive dragged back to the nests reinforces its place beneath King Gurgler.'),

        ('personality', '', 'fishman', 'Skittish alone but frenzied in a pack, a Gurgler is ruled by hunger, nest instinct and the pull of its kin. It has no interest in trade, talk or bargains, only in whether a stranger is prey, a threat, or something to drag back to the nest.'),
        ('backstory', '', 'fishman', 'Hatched from a clutch of eggs in the nests of Fishman Island, south of the Bonefields and Catun. It has spent its whole life roaming the island''s shallows and ancient ruins under mad rain and acid downpours, hunting with its pack beneath the Alpha Gurglers and King Gurgler.'),
        ('speechstyle', '', 'fishman', 'Cannot speak any human language and never forms words. It communicates only through wet gurgles, throaty croaks, clicks and hisses that rise to frantic burbling as the pack closes in, and through body language: a low crouch, spread claws, jerks of the head toward prey and swaying in time with its packmates.'),
        ('occupation', '', 'fishman', 'A pack hunter and nest scavenger of Fishman Island, south of Catun. It ambushes travelers in numbers, fights with its claws, and drags the wounded back to its nest to be eaten alive.'),
        ('appearance', '', 'fishman', 'A hunched, fish-like humanoid with slick, damp skin, a wide gaping mouth and long clawed hands. It moves in a low, loping crouch and is rarely seen without others of its kind close behind.'),
        ('goals', '', 'fishman', 'To feed, guard the nests and their eggs, and overwhelm intruders through sheer numbers. Wounded prey is carried home to the nest rather than left behind.')
)
INSERT INTO bio_random (type, description, name, race, gender, faction)
SELECT s.type, s.description, s.name, s.race, '', ''
FROM source_rows s
ON CONFLICT (type, description, name) DO UPDATE
SET updated_at = NOW();
COMMIT;
