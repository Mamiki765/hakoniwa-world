# ver 1.2.0 command input semantics

## Owner decision

Nation-target commands must not ask a player to enter a raw numeric `target_nation_id`.
The command-definition projection supplies a selector of selectable active Nations in the
same World, identified to the player by island name with the Nation number as supporting
information. The existing numeric `target_nation_id` remains the internal queue payload.

Command inputs are independent fields:

- a Nation target is an island selector;
- ordinary quantity is a numeric quantity input;
- a stable catalog selector is a type selector backed by a stable catalog identifier;
- unused quantity has no quantity input.

For ver 1.2.0, `money_aid` and `food_aid` combine the island selector with ordinary
quantity. `monster_dispatch` combines the island selector with unused quantity and keeps
the published v1/v2 fixed `mecha_inora` behavior. The published rulesets are unchanged.

The backend command projection marks parameter input semantics and supplies target
options. Registration validates the selected target against the same active, same-World,
not-self boundary, and turn execution revalidates mutable state. Frontend code does not
reproduce target eligibility rules.

Future monster type and dispatch-count inputs must remain separate from destination:
monster type uses a stable monster key (or another stable catalog identifier), quantity is
the count, and `target_nation_id` is the destination. New catalog types must not be encoded
as ordinal positions in quantity.
